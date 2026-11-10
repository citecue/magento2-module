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
 * Caching model (per page, per Accept variant, keyed by publicKey|epoch|variant|url):
 *  - The Accept header is reduced to a variant ("md" when text/markdown is
 *    preferred, else "html"). Each variant has its own cache entry and its own
 *    miss marker, because the API answers (and 404s) per variant. The canonical
 *    Accept for the variant — not the raw header — is what is sent upstream.
 *  - Every 200 stores {content, etag, mode, content_type, stored_at} locally.
 *    ENTRY_TTL (24h) only bounds how long an entry may be reused for 304
 *    revalidation; it is never a license to serve stale content on error.
 *  - Within "Local Cache TTL" seconds of stored_at the cached copy is served
 *    without an API round trip (0 = disabled).
 *  - Otherwise the API is revalidated with If-None-Match: a 304 refreshes
 *    stored_at and serves the cached body (the API still counts the hit as
 *    "served" — by design, see v2 page endpoint docs), a 200 replaces it.
 *  - A 404 purges that variant's cached entry (the optimization was undone or
 *    delivery switched off) and is negative-cached for 60s (the API's miss
 *    max-age).
 *  - A 401 (key revoked) rotates the project epoch, which orphans every cached
 *    entry of the project at once, and sets a 60s pass-through throttle keyed
 *    on the API key. It never trips the circuit breaker: a revoked key is not
 *    an outage and must not authorize stale serving.
 *  - A transport error / 5xx trips a 60s circuit breaker: while it's open,
 *    uncached pages pass through with no API call at all. Stale copies are
 *    served on error only within STALE_IF_ERROR (300s, the API's own max-age).
 */
class DeliveryService
{
    private const PAGE_CACHE_PREFIX = 'citecue_page_';
    private const MISS_CACHE_PREFIX = 'citecue_miss_';
    private const LLMS_CACHE_PREFIX = 'citecue_llms_';
    private const DOWN_FLAG_PREFIX = 'citecue_api_down_';
    private const KEY_INVALID_PREFIX = 'citecue_key_invalid_';
    private const EPOCH_PREFIX = 'citecue_epoch_';

    private const ENTRY_TTL = 86400;      // bounds 304-revalidation reuse of locally cached bodies
    private const MISS_TTL = 60;          // mirrors the API's miss Cache-Control max-age
    private const DOWN_TTL = 60;          // circuit-breaker window after a transport error
    private const LLMS_FRESH_SECONDS = 300; // llms.txt revalidation window (API max-age)
    private const KEY_INVALID_TTL = 60;   // pass-through throttle after a 401
    private const STALE_IF_ERROR = 300;   // max age of a body served on error (API max-age)

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
     * @return array{content: string, mode: string, crawler: string, content_type: string}|null
     */
    public function getCrawlerPage(HttpRequest $request): ?array
    {
        $storeId = $this->currentStoreId();
        if (!$this->config->isConfigured($storeId)) {
            return null;
        }
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
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
        $apiKey = $this->config->getApiKey($storeId);

        // Revoked key: pure TTL throttle, no API call until it self-expires.
        if ($this->cache->load($this->keyInvalidKey($publicKey, $apiKey))) {
            return null;
        }

        $accept = $request->getHeader('Accept');
        $variant = $this->acceptVariant(is_string($accept) ? $accept : null);
        $epoch = $this->epoch($publicKey);
        $cacheKey = $this->pageKey($publicKey, $epoch, $variant, $url);
        $missKey = $this->pageMissKey($publicKey, $variant, $url);
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
        if ($this->cache->load($missKey)) {
            return null;
        }

        // Circuit breaker open — a young stale copy if we have one, passthrough if not.
        if ($this->cache->load($downKey)) {
            return $entry !== null && $this->isStaleServable($entry)
                ? $this->pageResult($entry, $crawler['id'])
                : null;
        }

        $result = $this->client->fetchPage(
            $url,
            $crawler['id'],
            $entry['etag'] ?? null,
            $storeId,
            $this->canonicalAccept($variant)
        );

        if ($result['status'] === 200 && $result['body'] !== null) {
            $entry = [
                'content' => $result['body'],
                'etag' => $result['etag'],
                'mode' => $result['mode'] ?? 'enriched',
                'content_type' => $this->sanitizeContentType($result['content_type'] ?? null),
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
            // No optimized version (any more) — drop this variant's cached copy,
            // remember the miss briefly, pass through. The API 404s per variant,
            // so the sibling variant revalidates itself. The API already
            // recorded the passthrough hit for Agent Traffic.
            $this->cache->remove($cacheKey);
            $this->cache->save('1', $missKey, [DeliveryCache::STATE_TAG], self::MISS_TTL);
            return null;
        }

        if ($result['status'] === 401) {
            $this->logInvalidKeyWarning($storeId);
            $this->rotateEpoch($publicKey);
            $this->cache->save(
                '1',
                $this->keyInvalidKey($publicKey, $apiKey),
                [DeliveryCache::STATE_TAG],
                self::KEY_INVALID_TTL
            );
            return null;
        }

        // Transport error (0) or 5xx: trip the breaker, serve a young stale copy if possible.
        $this->cache->save('1', $downKey, [DeliveryCache::STATE_TAG], self::DOWN_TTL);
        if ($entry !== null && $this->isStaleServable($entry)) {
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
        $apiKey = $this->config->getApiKey($storeId);
        if ($this->cache->load($this->keyInvalidKey($publicKey, $apiKey))) {
            return null;
        }

        $cacheKey = $this->llmsKey($publicKey, $this->epoch($publicKey));
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
            return $entry !== null && $this->isStaleServable($entry)
                ? ['content' => $entry['content'], 'etag' => $entry['etag']]
                : null;
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
            $this->cache->remove($cacheKey);
            $this->cache->save('1', $missKey, [DeliveryCache::STATE_TAG], self::MISS_TTL);
            return null;
        }
        if ($result['status'] === 401) {
            $this->logInvalidKeyWarning($storeId);
            $this->rotateEpoch($publicKey);
            $this->cache->save(
                '1',
                $this->keyInvalidKey($publicKey, $apiKey),
                [DeliveryCache::STATE_TAG],
                self::KEY_INVALID_TTL
            );
            return null;
        }

        $this->cache->save('1', $downKey, [DeliveryCache::STATE_TAG], self::DOWN_TTL);
        return $entry !== null && $this->isStaleServable($entry)
            ? ['content' => $entry['content'], 'etag' => $entry['etag']]
            : null;
    }

    /**
     * Logs a rejected API key, adding a hint when the configured Base URL was
     * ignored by the host allowlist (the key then went to the default host).
     *
     * @param int|string|null $storeId
     * @return void
     */
    private function logInvalidKeyWarning($storeId): void
    {
        $message = 'Citecue: the delivery API rejected the configured API key (401 invalid_key).';
        try {
            $rejected = $this->config->getRejectedBaseUrl($storeId) !== null;
        } catch (\Throwable $e) {
            // A diagnostic hint must never break the fail-open delivery path.
            $rejected = false;
        }
        if ($rejected) {
            $message .= ' The configured Base URL is not on the Allowed API Hosts list, so the request went to'
                . ' the default host — a key for a self-hosted Citecue will be rejected there.';
        }
        $this->logger->warning($message);
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
     * Cache key of the project epoch (see rotateEpoch).
     *
     * @param string $publicKey
     * @return string
     */
    private function epochKey(string $publicKey): string
    {
        return self::EPOCH_PREFIX . hash('sha256', $publicKey);
    }

    /**
     * The project's current cache epoch, created on first use.
     *
     * @param string $publicKey
     * @return string
     */
    private function epoch(string $publicKey): string
    {
        $epoch = $this->cache->load($this->epochKey($publicKey));
        return is_string($epoch) && $epoch !== '' ? $epoch : $this->rotateEpoch($publicKey);
    }

    /**
     * Replaces the project epoch, orphaning every page/llms entry cached under
     * the old one in O(1).
     *
     * If the epoch itself is ever evicted a fresh random one is generated, so
     * failure only orphans entries and can never resurrect old ones.
     *
     * @param string $publicKey
     * @return string
     */
    private function rotateEpoch(string $publicKey): string
    {
        $epoch = bin2hex(random_bytes(8));
        $this->cache->save($epoch, $this->epochKey($publicKey), [DeliveryCache::STATE_TAG], self::ENTRY_TTL);
        return $epoch;
    }

    /**
     * Key of the "API key was rejected" throttle flag. Keyed on the API key so
     * a newly pasted key is tried immediately, even when changed via CLI.
     *
     * @param string $publicKey
     * @param string $apiKey
     * @return string
     */
    private function keyInvalidKey(string $publicKey, string $apiKey): string
    {
        return self::KEY_INVALID_PREFIX . hash('sha256', $publicKey . '|' . hash('sha256', $apiKey));
    }

    /**
     * Page entry key. The variant sits before the URL: a URL always starts
     * with a scheme, so no crafted URL can collide with another variant.
     *
     * @param string $publicKey
     * @param string $epoch
     * @param string $variant
     * @param string $url
     * @return string
     */
    private function pageKey(string $publicKey, string $epoch, string $variant, string $url): string
    {
        return self::PAGE_CACHE_PREFIX . hash('sha256', $publicKey . '|' . $epoch . '|' . $variant . '|' . $url);
    }

    /**
     * Miss-marker key for one page variant.
     *
     * @param string $publicKey
     * @param string $variant
     * @param string $url
     * @return string
     */
    private function pageMissKey(string $publicKey, string $variant, string $url): string
    {
        return self::MISS_CACHE_PREFIX . hash('sha256', $publicKey . '|' . $variant . '|' . $url);
    }

    /**
     * llms.txt entry key.
     *
     * @param string $publicKey
     * @param string $epoch
     * @return string
     */
    private function llmsKey(string $publicKey, string $epoch): string
    {
        return self::LLMS_CACHE_PREFIX . hash('sha256', $publicKey . '|' . $epoch);
    }

    /**
     * Whether an entry is young enough to be served when the API is unavailable.
     *
     * @param array{content:string,etag:string|null,mode:string,content_type:string,stored_at:int} $entry
     * @return bool
     */
    private function isStaleServable(array $entry): bool
    {
        return (time() - $entry['stored_at']) < self::STALE_IF_ERROR;
    }

    /**
     * Reduces an Accept header to the representation variant to serve:
     * "md" iff text/markdown is acceptable and at least as preferred as both
     * text/html and application/xhtml+xml (absent types count as q=0).
     *
     * @param string|null $accept
     * @return string "md" or "html"
     */
    private function acceptVariant(?string $accept): string
    {
        if ($accept === null || trim($accept) === '') {
            return 'html';
        }
        $quality = ['text/markdown' => 0.0, 'text/html' => 0.0, 'application/xhtml+xml' => 0.0];
        foreach (explode(',', $accept) as $token) {
            $parts = explode(';', $token);
            $type = strtolower(trim($parts[0]));
            if (!isset($quality[$type])) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($parts, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                    $q = (float)$m[1];
                }
            }
            $quality[$type] = max($quality[$type], $q);
        }
        return $quality['text/markdown'] > 0.0
            && $quality['text/markdown'] >= $quality['text/html']
            && $quality['text/markdown'] >= $quality['application/xhtml+xml']
            ? 'md'
            : 'html';
    }

    /**
     * The Accept value sent upstream for a variant. Forwarding this canonical
     * value (not the raw header) keeps the cache key and the upstream
     * representation in agreement by construction.
     *
     * @param string $variant
     * @return string
     */
    private function canonicalAccept(string $variant): string
    {
        return $variant === 'md' ? 'text/markdown, text/html;q=0.9' : 'text/html';
    }

    /**
     * Upstream Content-Type, or the HTML default unless it is a plain text/*
     * type with at most a charset parameter (it is echoed into a response header).
     *
     * @param string|null $raw
     * @return string
     */
    private function sanitizeContentType(?string $raw): string
    {
        $value = trim((string)$raw);
        if (preg_match('#^text/[a-z0-9.+-]+(;\s*charset=[A-Za-z0-9._-]+)?$#i', $value)) {
            return $value;
        }
        return 'text/html; charset=UTF-8';
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
     * @param array{content:string,etag:string|null,mode:string,content_type:string,stored_at:int} $entry
     * @param string $crawlerId
     * @return array{content:string,mode:string,crawler:string,content_type:string}
     */
    private function pageResult(array $entry, string $crawlerId): array
    {
        return [
            'content' => $entry['content'],
            'mode' => (string)($entry['mode'] ?? 'enriched'),
            'crawler' => $crawlerId,
            'content_type' => $entry['content_type'],
        ];
    }

    /**
     * Loads and validates a locally cached delivery entry.
     *
     * @param string $cacheKey
     * @return array{content:string,etag:string|null,mode:string,content_type:string,stored_at:int}|null
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
                'content_type' => isset($decoded['content_type'])
                    && is_string($decoded['content_type'])
                    && $decoded['content_type'] !== ''
                    ? $decoded['content_type']
                    : 'text/html; charset=UTF-8',
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
     * @param array{content:string,etag:string|null,mode:string,content_type:string,stored_at:int} $entry
     * @return void
     */
    private function saveEntry(string $cacheKey, array $entry): void
    {
        try {
            // save() reports a backend write failure as false, not an exception.
            $saved = $this->cache->save(
                $this->json->serialize($entry),
                $cacheKey,
                [DeliveryCache::STATE_TAG],
                self::ENTRY_TTL
            );
            if (!$saved) {
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
