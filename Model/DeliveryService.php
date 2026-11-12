<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model;

use Citecue\Delivery\Model\Api\Client;
use Citecue\Delivery\Model\Cache\Type as DeliveryCache;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The middleware brain: decides whether a request comes from a servable AI
 * crawler and, if so, produces the Citecue-optimized page (or llms.txt) to
 * serve — otherwise returns null and Magento renders the page as usual.
 *
 * Posture: FAIL OPEN, always. A miss, an API outage, a timeout, a bad key —
 * every failure path returns null (crawler gets the normal page) or a stale
 * cached copy. This class must never let the middleware take a store down.
 *
 * Caching model (per page, keyed by publicKey|url):
 *  - Every 200 stores {content, etag, mode, stored_at} locally for 24h.
 *  - Within "Local Cache TTL" seconds of stored_at the cached copy is served
 *    without an API round trip (0 = disabled).
 *  - Otherwise the API is revalidated with If-None-Match: a 304 refreshes
 *    stored_at and serves the cached body (the API still counts the hit as
 *    "served" — by design, see v2 page endpoint docs), a 200 replaces it.
 *  - Misses are negative-cached for 60s (matching the API's miss max-age).
 *  - A transport error / 5xx trips a 60s circuit breaker: while it's open,
 *    cached copies (up to 24h stale) are served and uncached pages pass
 *    through with no API call at all.
 */
class DeliveryService
{
    private const PAGE_CACHE_PREFIX = 'citecue_page_';
    private const MISS_CACHE_PREFIX = 'citecue_miss_';
    private const LLMS_CACHE_PREFIX = 'citecue_llms_';
    private const DOWN_FLAG_PREFIX = 'citecue_api_down_';

    private const ENTRY_TTL = 86400;      // stale ceiling for locally cached bodies
    private const MISS_TTL = 60;          // mirrors the API's miss Cache-Control max-age
    private const DOWN_TTL = 60;          // circuit-breaker window after a transport error
    private const LLMS_FRESH_SECONDS = 300; // llms.txt revalidation window (API max-age)

    /**
     * @var Config
     */
    private $config;

    /**
     * @var CrawlerRegistry
     */
    private $crawlerRegistry;

    /**
     * @var PathMatcher
     */
    private $pathMatcher;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var DeliveryCache
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param CrawlerRegistry $crawlerRegistry
     * @param PathMatcher $pathMatcher
     * @param Client $client
     * @param DeliveryCache $cache
     * @param Json $json
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        CrawlerRegistry $crawlerRegistry,
        PathMatcher $pathMatcher,
        Client $client,
        DeliveryCache $cache,
        Json $json,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->crawlerRegistry = $crawlerRegistry;
        $this->pathMatcher = $pathMatcher;
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Cheap pre-check used by the FPC bypass plugin.
     *
     * Is this request from a servable AI crawler on a store where the
     * middleware is active?
     *
     * @param HttpRequest $request
     * @return bool
     */
    public function isCrawlerRequest(HttpRequest $request): bool
    {
        try {
            $storeId = $this->currentStoreId();
            if (!$this->config->isConfigured($storeId)) {
                return false;
            }
            return $this->crawlerRegistry->matchServable($this->userAgent($request)) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The main middleware decision for a frontend request. Returns the page
     * to serve to the AI crawler, or null to pass through to normal Magento
     * rendering.
     *
     * @param HttpRequest $request
     * @return array{content: string, mode: string, crawler: string}|null
     */
    public function getCrawlerPage(HttpRequest $request): ?array
    {
        $storeId = $this->currentStoreId();
        if (!$this->config->isConfigured($storeId)) {
            return null;
        }
        if (strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }

        $crawler = $this->crawlerRegistry->matchServable($this->userAgent($request));
        if ($crawler === null) {
            return null;
        }

        $pathInfo = (string)$request->getPathInfo();
        if ($this->pathMatcher->isExcluded($pathInfo, $this->config->getExcludedPathPrefixes($storeId))
            || trim($pathInfo, '/') === 'llms.txt'
        ) {
            return null;
        }

        $url = $this->currentUrl($request);
        $publicKey = $this->config->getPublicKey($storeId);
        $cacheKey = self::PAGE_CACHE_PREFIX . hash('sha256', $publicKey . '|' . $url);
        $downKey = $this->downFlagKey($publicKey);
        $entry = $this->loadEntry($cacheKey);
        $debug = $this->config->isDebugLogging($storeId);

        // Fresh local copy — serve without a round trip.
        $localTtl = $this->config->getLocalCacheTtl($storeId);
        if ($entry !== null && $localTtl > 0 && (time() - $entry['stored_at']) < $localTtl) {
            if ($debug) {
                $this->logger->debug(
                    'Citecue: serving locally cached page to ' . $crawler['id']
                    . ' for ' . $this->redactUrl($url)
                );
            }
            return $this->pageResult($entry, $crawler['id']);
        }

        // Recent miss — don't re-ask the API yet.
        if ($this->cache->load(self::MISS_CACHE_PREFIX . hash('sha256', $publicKey . '|' . $url))) {
            return null;
        }

        // Circuit breaker open — stale copy if we have one, passthrough if not.
        if ($this->cache->load($downKey)) {
            return $entry !== null ? $this->pageResult($entry, $crawler['id']) : null;
        }

        $result = $this->client->fetchPage($url, $crawler['id'], $entry['etag'] ?? null, $storeId);

        if ($result['status'] === 200 && $result['body'] !== null) {
            $entry = [
                'content' => $result['body'],
                'etag' => $result['etag'],
                'mode' => $result['mode'] ?? 'enriched',
                'stored_at' => time(),
            ];
            $this->saveEntry($cacheKey, $entry);
            if ($debug) {
                $this->logger->debug(
                    'Citecue: serving optimized page (' . $entry['mode'] . ') to ' . $crawler['id']
                    . ' for ' . $this->redactUrl($url)
                );
            }
            return $this->pageResult($entry, $crawler['id']);
        }

        if ($result['status'] === 304 && $entry !== null) {
            $entry['stored_at'] = time();
            $this->saveEntry($cacheKey, $entry);
            if ($debug) {
                $this->logger->debug(
                    'Citecue: 304 revalidated, serving cached page to ' . $crawler['id']
                    . ' for ' . $this->redactUrl($url)
                );
            }
            return $this->pageResult($entry, $crawler['id']);
        }

        if ($result['status'] === 404) {
            // No optimized version — remember briefly, pass through. The API
            // already recorded the passthrough hit for Agent Traffic.
            $this->cache->save(
                '1',
                self::MISS_CACHE_PREFIX . hash('sha256', $publicKey . '|' . $url),
                [],
                self::MISS_TTL
            );
            return null;
        }

        if ($result['status'] === 401) {
            $this->logger->warning('Citecue: the delivery API rejected the configured API key (401 invalid_key).');
            $this->cache->save('1', $downKey, [], self::DOWN_TTL);
            return null;
        }

        // Transport error (0) or 5xx: trip the breaker, serve stale if possible.
        $this->cache->save('1', $downKey, [], self::DOWN_TTL);
        if ($entry !== null) {
            if ($debug) {
                $this->logger->debug(
                    'Citecue: API unavailable, serving stale cached page to ' . $crawler['id']
                    . ' for ' . $this->redactUrl($url)
                );
            }
            return $this->pageResult($entry, $crawler['id']);
        }
        return null;
    }

    /**
     * The llms.txt body for the current store, or null (→ 404).
     *
     * @return array{content: string, etag: string|null}|null
     */
    public function getLlmsTxt(): ?array
    {
        $storeId = $this->currentStoreId();
        if (!$this->config->isConfigured($storeId) || !$this->config->isServeLlmsTxt($storeId)) {
            return null;
        }

        $publicKey = $this->config->getPublicKey($storeId);
        $cacheKey = self::LLMS_CACHE_PREFIX . hash('sha256', $publicKey);
        $missKey = self::MISS_CACHE_PREFIX . hash('sha256', $publicKey . '|llms.txt');
        $downKey = $this->downFlagKey($publicKey);
        $entry = $this->loadEntry($cacheKey);

        if ($entry !== null && (time() - $entry['stored_at']) < self::LLMS_FRESH_SECONDS) {
            return ['content' => $entry['content'], 'etag' => $entry['etag']];
        }
        if ($this->cache->load($missKey)) {
            return null;
        }
        if ($this->cache->load($downKey)) {
            return $entry !== null ? ['content' => $entry['content'], 'etag' => $entry['etag']] : null;
        }

        $result = $this->client->fetchLlmsTxt($entry['etag'] ?? null, $storeId);

        if ($result['status'] === 200 && $result['body'] !== null) {
            $entry = [
                'content' => $result['body'],
                'etag' => $result['etag'],
                'mode' => 'llms',
                'stored_at' => time(),
            ];
            $this->saveEntry($cacheKey, $entry);
            return ['content' => $entry['content'], 'etag' => $entry['etag']];
        }
        if ($result['status'] === 304 && $entry !== null) {
            $entry['stored_at'] = time();
            $this->saveEntry($cacheKey, $entry);
            return ['content' => $entry['content'], 'etag' => $entry['etag']];
        }
        if ($result['status'] === 404) {
            $this->cache->save('1', $missKey, [], self::MISS_TTL);
            return null;
        }
        if ($result['status'] === 401) {
            $this->logger->warning('Citecue: the delivery API rejected the configured API key (401 invalid_key).');
            $this->cache->save('1', $downKey, [], self::DOWN_TTL);
            return null;
        }

        $this->cache->save('1', $downKey, [], self::DOWN_TTL);
        return $entry !== null ? ['content' => $entry['content'], 'etag' => $entry['etag']] : null;
    }

    /**
     * Circuit-breaker cache key, scoped per project (public key). In a
     * multi-store install a bad key or unreachable base URL for one store
     * view must not trip the breaker for every other store, so the flag is
     * never global.
     *
     * @param string $publicKey
     * @return string
     */
    private function downFlagKey(string $publicKey): string
    {
        return self::DOWN_FLAG_PREFIX . hash('sha256', $publicKey);
    }

    /**
     * Strips the query string (and everything after) from a URL for logging,
     * keeping only scheme://host/path. Crawler URLs can carry query values
     * that shouldn't be persisted verbatim in var/log. The full raw URL is
     * still sent to the API — only the log representation is redacted.
     *
     * @param string $url
     * @return string
     */
    private function redactUrl(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- log redaction only; no framework URL parser fits
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '[redacted-url]';
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        return $scheme . $parts['host'] . ($parts['path'] ?? '');
    }

    /**
     * Shapes a cached entry into the middleware's serve result.
     *
     * @param array{content:string,etag:string|null,mode:string,stored_at:int} $entry
     * @param string $crawlerId
     * @return array{content:string,mode:string,crawler:string}
     */
    private function pageResult(array $entry, string $crawlerId): array
    {
        return [
            'content' => $entry['content'],
            'mode' => (string)($entry['mode'] ?? 'enriched'),
            'crawler' => $crawlerId,
        ];
    }

    /**
     * Loads and validates a locally cached delivery entry.
     *
     * @param string $cacheKey
     * @return array{content:string,etag:string|null,mode:string,stored_at:int}|null
     */
    private function loadEntry(string $cacheKey): ?array
    {
        try {
            $raw = $this->cache->load($cacheKey);
            if (!$raw) {
                return null;
            }
            $decoded = $this->json->unserialize($raw);
            if (!is_array($decoded) || !isset($decoded['content'])) {
                return null;
            }
            return [
                'content' => (string)$decoded['content'],
                'etag' => isset($decoded['etag']) && $decoded['etag'] !== '' ? (string)$decoded['etag'] : null,
                'mode' => (string)($decoded['mode'] ?? 'enriched'),
                'stored_at' => (int)($decoded['stored_at'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Persists a delivery entry to the local cache; failures are logged only.
     *
     * @param string $cacheKey
     * @param array{content:string,etag:string|null,mode:string,stored_at:int} $entry
     * @return void
     */
    private function saveEntry(string $cacheKey, array $entry): void
    {
        try {
            // save() reports a backend write failure as false, not an exception.
            if (!$this->cache->save($this->json->serialize($entry), $cacheKey, [], self::ENTRY_TTL)) {
                $this->logger->warning('Citecue: the delivery cache backend refused the write for ' . $cacheKey);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Citecue: failed to write the delivery cache: ' . $e->getMessage());
        }
    }

    /**
     * The request's User-Agent header, or null when absent.
     *
     * @param HttpRequest $request
     * @return string|null
     */
    private function userAgent(HttpRequest $request): ?string
    {
        $ua = $request->getHeader('User-Agent');
        return is_string($ua) && $ua !== '' ? $ua : null;
    }

    /**
     * Absolute URL as requested by the crawler.
     *
     * Sent raw — the API strips tracking params, www and trailing slashes
     * itself (normalizePageUrl).
     *
     * @param HttpRequest $request
     * @return string
     */
    private function currentUrl(HttpRequest $request): string
    {
        return $request->getScheme() . '://' . $request->getHttpHost() . $request->getRequestUri();
    }

    /**
     * The current store id, or null when store resolution fails.
     *
     * @return int|null
     */
    private function currentStoreId(): ?int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
