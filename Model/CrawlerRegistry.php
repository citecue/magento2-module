<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model;

use Citecue\Delivery\Model\Cache\Type as DeliveryCache;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The AI-crawler registry the middleware matches User-Agents against.
 *
 * Ships with a bundled snapshot of Citecue's registry (version 1, mirroring
 * citecue_app shared/utils/deliveryCrawlers.ts) so detection works with zero
 * network calls, and is refreshed daily by a cron job from the keyless
 * GET /api/delivery/v1/crawlers feed — a crawler added upstream reaches the
 * store without a module update. UA matching NEVER touches the network on
 * the request path: it only ever reads the bundled list or the cached feed.
 */
class CrawlerRegistry
{
    public const BUNDLED_REGISTRY_VERSION = 1;

    private const CACHE_KEY = 'citecue_delivery_crawler_registry';
    private const CACHE_TTL = 604800; // 7 days: survives cron misfires, refreshed daily

    /**
     * Bundled snapshot of the upstream delivery crawler registry (v1).
     * `fetchesPages: false` entries are robots.txt opt-out tokens that never
     * send real HTTP requests; they stay listed for parity with upstream but
     * are never served.
     */
    private const BUNDLED_CRAWLERS = [
        ['id' => 'gptbot', 'token' => 'GPTBot', 'fetchesPages' => true],
        ['id' => 'oai-searchbot', 'token' => 'OAI-SearchBot', 'fetchesPages' => true],
        ['id' => 'chatgpt-user', 'token' => 'ChatGPT-User', 'fetchesPages' => true],
        ['id' => 'claudebot', 'token' => 'ClaudeBot', 'fetchesPages' => true],
        ['id' => 'claude-user', 'token' => 'Claude-User', 'fetchesPages' => true],
        ['id' => 'claude-searchbot', 'token' => 'Claude-SearchBot', 'fetchesPages' => true],
        ['id' => 'anthropic-ai', 'token' => 'anthropic-ai', 'fetchesPages' => true],
        ['id' => 'perplexitybot', 'token' => 'PerplexityBot', 'fetchesPages' => true],
        ['id' => 'perplexity-user', 'token' => 'Perplexity-User', 'fetchesPages' => true],
        ['id' => 'google-extended', 'token' => 'Google-Extended', 'fetchesPages' => false],
        ['id' => 'googleother', 'token' => 'GoogleOther', 'fetchesPages' => true],
        ['id' => 'bytespider', 'token' => 'Bytespider', 'fetchesPages' => true],
        ['id' => 'ccbot', 'token' => 'CCBot', 'fetchesPages' => true],
        ['id' => 'cohere-ai', 'token' => 'cohere-ai', 'fetchesPages' => true],
        ['id' => 'meta-externalagent', 'token' => 'meta-externalagent', 'fetchesPages' => true],
        ['id' => 'meta-externalfetcher', 'token' => 'meta-externalfetcher', 'fetchesPages' => true],
        ['id' => 'amazonbot', 'token' => 'Amazonbot', 'fetchesPages' => true],
        ['id' => 'applebot-extended', 'token' => 'Applebot-Extended', 'fetchesPages' => false],
        ['id' => 'duckassistbot', 'token' => 'DuckAssistBot', 'fetchesPages' => true],
        ['id' => 'mistralai-user', 'token' => 'MistralAI-User', 'fetchesPages' => true],
    ];

    /**
     * @var DeliveryCache
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Per-request memoization of the effective crawler list.
     *
     * @var array<int, array{id: string, token: string, fetchesPages: bool}>|null
     */
    private $crawlers = null;

    /**
     * @param DeliveryCache $cache
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        DeliveryCache $cache,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * The effective crawler list: the cached remote feed when present and
     * valid, otherwise the bundled snapshot.
     *
     * @return array<int, array{id: string, token: string, fetchesPages: bool}>
     */
    public function getCrawlers(): array
    {
        if ($this->crawlers !== null) {
            return $this->crawlers;
        }
        $this->crawlers = self::BUNDLED_CRAWLERS;
        try {
            $cached = $this->cache->load(self::CACHE_KEY);
            if ($cached) {
                $decoded = $this->json->unserialize($cached);
                $crawlers = $this->sanitize(is_array($decoded) ? ($decoded['crawlers'] ?? null) : null);
                if ($crawlers !== null) {
                    $this->crawlers = $crawlers;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Citecue: failed to read cached crawler registry: ' . $e->getMessage());
        }
        return $this->crawlers;
    }

    /**
     * Matches a UA against the effective registry; returns the crawler to
     * serve, or null when the visitor is not a servable AI crawler.
     *
     * @param string|null $userAgent
     * @return array{id: string, token: string, fetchesPages: bool}|null
     */
    public function matchServable(?string $userAgent): ?array
    {
        return CrawlerMatcher::matchServable($this->getCrawlers(), $userAgent);
    }

    /**
     * Persists a freshly fetched remote registry (called by the cron job).
     *
     * @param array<int, mixed> $crawlers
     * @param int $version
     * @return bool whether the payload was valid and stored
     */
    public function store(array $crawlers, int $version): bool
    {
        $sanitized = $this->sanitize($crawlers);
        if ($sanitized === null) {
            return false;
        }
        $payload = $this->json->serialize([
            'version' => $version,
            'crawlers' => $sanitized,
            'fetched_at' => time(),
        ]);
        // save() returns false on a backend write failure; don't report a
        // successful refresh (and don't drop the memoized list) if the new
        // registry never actually persisted.
        if (!$this->cache->save($payload, self::CACHE_KEY, [], self::CACHE_TTL)) {
            return false;
        }
        $this->crawlers = null;
        return true;
    }

    /**
     * Validates and normalizes an untrusted crawler list; null when unusable.
     *
     * @param mixed $raw
     * @return array<int, array{id: string, token: string, fetchesPages: bool}>|null
     */
    private function sanitize($raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $result = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? trim((string)$row['id']) : '';
            $token = isset($row['token']) ? trim((string)$row['token']) : '';
            if ($id === '' || $token === '') {
                continue;
            }
            $result[] = [
                'id' => $id,
                'token' => $token,
                // Strict boolean: a malformed remote row (e.g. the string
                // "false") must not flip a non-serving token into a serving
                // one — only a literal true opts a crawler into being served.
                'fetchesPages' => ($row['fetchesPages'] ?? false) === true,
            ];
        }
        return $result !== [] ? $result : null;
    }
}
