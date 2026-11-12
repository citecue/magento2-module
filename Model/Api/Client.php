<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model\Api;

use Citecue\Delivery\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Citecue delivery API (v2 authenticated channel +
 * the keyless v1 crawler registry feed).
 *
 * Contract (from citecue_app server/api/delivery):
 *  - Auth: "Authorization: Bearer ck_live_…" org API key.
 *  - Channel: "X-Citecue-Channel: magento" attributes hits in Agent Traffic.
 *  - GET /api/delivery/v2/page?k=<publicKey>&u=<rawUrl>&b=<crawlerId>
 *      200 HTML + ETag + X-Citecue-Mode | 304 on If-None-Match |
 *      404 "not_optimized" miss sentinel | 401 {"error":"invalid_key"}.
 *      The server records one served/passthrough crawler hit per request.
 *  - GET /api/delivery/v2/llms.txt?k=<publicKey>   same posture, text/plain.
 *  - GET /api/delivery/v2/config                    {projects:[{publicKey,domain,enabled,serveLlmsTxt}]}
 *  - GET /api/delivery/v1/crawlers                  keyless {version,tokens,crawlers}.
 *
 * Never throws: every method returns a result array whose 'status' is the
 * HTTP status, or 0 for a transport error (timeout/DNS/TLS) — callers treat
 * 0 and 5xx as "API unavailable" and fail open.
 */
class Client
{
    public const VERSION = '1.0.0';
    public const CHANNEL = 'magento';

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        CurlFactory $curlFactory,
        Config $config,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Fetch the optimized version of a page for an AI crawler.
     *
     * @param string $url Absolute URL the crawler requested (sent raw; the API normalizes it)
     * @param string $crawlerId Registry id (e.g. "gptbot"); the API renormalizes ids and tokens
     * @param string|null $etag Cached ETag for conditional revalidation
     * @param int|string|null $storeId
     * @return array{status: int, body: string|null, etag: string|null, mode: string|null}
     */
    public function fetchPage(string $url, string $crawlerId, ?string $etag, $storeId = null): array
    {
        $endpoint = $this->config->getBaseUrl($storeId) . '/api/delivery/v2/page?' . http_build_query([
            'k' => $this->config->getPublicKey($storeId),
            'u' => $url,
            'b' => $crawlerId,
        ]);
        $headers = $this->authHeaders($this->config->getApiKey($storeId));
        $headers['Accept'] = 'text/html';
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        return $this->request($endpoint, $headers, $storeId);
    }

    /**
     * Fetch the project's llms.txt.
     *
     * @param string|null $etag
     * @param int|string|null $storeId
     * @return array{status: int, body: string|null, etag: string|null, mode: string|null}
     */
    public function fetchLlmsTxt(?string $etag, $storeId = null): array
    {
        $endpoint = $this->config->getBaseUrl($storeId) . '/api/delivery/v2/llms.txt?' . http_build_query([
            'k' => $this->config->getPublicKey($storeId),
        ]);
        $headers = $this->authHeaders($this->config->getApiKey($storeId));
        $headers['Accept'] = 'text/plain';
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        return $this->request($endpoint, $headers, $storeId);
    }

    /**
     * Connection test: list the organization's delivery projects.
     *
     * @param string|null $apiKeyOverride Plaintext key to test before saving (falls back to saved config)
     * @param string|null $baseUrlOverride
     * @param int|string|null $storeId
     * @return array{status: int, projects: array<int, array<string, mixed>>|null, error: string|null}
     */
    public function fetchConfig(?string $apiKeyOverride = null, ?string $baseUrlOverride = null, $storeId = null): array
    {
        $hasKeyOverride = $apiKeyOverride !== null && trim($apiKeyOverride) !== '';
        $hasBaseOverride = $baseUrlOverride !== null && trim($baseUrlOverride) !== '';

        // SSRF guard: never send the stored API key to an operator-typed base
        // URL. A typed-in base URL is only honored together with an explicit
        // key override (the admin is testing a brand-new endpoint + key pair);
        // otherwise fall back to the saved, validated base URL and saved key.
        $base = ($hasBaseOverride && $hasKeyOverride)
            ? rtrim(trim((string)$baseUrlOverride), '/')
            : $this->config->getBaseUrl($storeId);
        $apiKey = $hasKeyOverride ? trim((string)$apiKeyOverride) : $this->config->getApiKey($storeId);

        if (!$this->config->isAllowedBaseUrl($base, $storeId)) {
            return [
                'status' => 0,
                'projects' => null,
                'error' => 'The Citecue API Base URL host is not on the Allowed API Hosts list. '
                    . 'Add it under Advanced → Allowed API Hosts and save first.',
            ];
        }

        $result = $this->request($base . '/api/delivery/v2/config', $this->authHeaders($apiKey), $storeId);
        if ($result['status'] !== 200 || $result['body'] === null) {
            return [
                'status' => $result['status'],
                'projects' => null,
                'error' => $this->describeFailure($result['status']),
            ];
        }
        try {
            $decoded = $this->json->unserialize($result['body']);
        } catch (\Throwable $e) {
            return [
                'status' => $result['status'],
                'projects' => null,
                'error' => 'Unexpected response from the Citecue API.',
            ];
        }
        $projects = is_array($decoded) && isset($decoded['projects']) && is_array($decoded['projects'])
            ? $decoded['projects']
            : [];
        return ['status' => 200, 'projects' => $projects, 'error' => null];
    }

    /**
     * Fetch the keyless AI-crawler registry feed (cron only, never hot path).
     *
     * @param int|string|null $storeId
     * @return array{version: int, crawlers: array<int, mixed>}|null
     */
    public function fetchCrawlerRegistry($storeId = null): ?array
    {
        $result = $this->request(
            $this->config->getBaseUrl($storeId) . '/api/delivery/v1/crawlers',
            ['Accept' => 'application/json', 'User-Agent' => $this->userAgent()],
            $storeId
        );
        if ($result['status'] !== 200 || $result['body'] === null) {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($result['body']);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($decoded) || !isset($decoded['crawlers']) || !is_array($decoded['crawlers'])) {
            return null;
        }
        return [
            'version' => (int)($decoded['version'] ?? 0),
            'crawlers' => $decoded['crawlers'],
        ];
    }

    /**
     * Standard headers for the authenticated v2 channel.
     *
     * @param string $apiKey
     * @return array<string,string>
     */
    private function authHeaders(string $apiKey): array
    {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'X-Citecue-Channel' => self::CHANNEL,
            'User-Agent' => $this->userAgent(),
        ];
    }

    /**
     * The User-Agent this module identifies itself with to the Citecue API.
     *
     * @return string
     */
    private function userAgent(): string
    {
        return 'CitecueMagento/' . self::VERSION;
    }

    /**
     * Executes a GET and normalizes the outcome; transport errors → status 0.
     *
     * @param string $endpoint
     * @param array<string,string> $headers
     * @param int|string|null $storeId
     * @return array{status: int, body: string|null, etag: string|null, mode: string|null}
     */
    private function request(string $endpoint, array $headers, $storeId = null): array
    {
        // Single choke point: credentialed requests may only target an https,
        // allowlisted host (private/loopback/link-local IPs always rejected).
        // Anything else is refused before the Bearer key is sent — treated as a
        // transport failure so the caller fails open. The hot path always
        // resolves an allowlisted host via Config::getBaseUrl; this also guards
        // the Test Connection base-URL override.
        if (!$this->config->isAllowedBaseUrl($endpoint, $storeId)) {
            if ($this->config->isDebugLogging($storeId)) {
                $this->logger->debug(
                    'Citecue: refusing delivery endpoint not on the allowed API host list: '
                    . $this->redact($endpoint)
                );
            }
            return ['status' => 0, 'body' => null, 'etag' => null, 'mode' => null];
        }

        $curl = $this->curlFactory->create();
        try {
            $curl->setTimeout($this->config->getTimeout($storeId));
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, $this->config->getConnectTimeout($storeId));
            $curl->setHeaders($headers);
            $curl->get($endpoint);
        } catch (\Throwable $e) {
            if ($this->config->isDebugLogging($storeId)) {
                $this->logger->debug(
                    'Citecue: transport error for ' . $this->redact($endpoint) . ': ' . $e->getMessage()
                );
            }
            return ['status' => 0, 'body' => null, 'etag' => null, 'mode' => null];
        }

        $status = (int)$curl->getStatus();
        $responseHeaders = $this->normalizeHeaders((array)$curl->getHeaders());
        return [
            'status' => $status,
            'body' => $status === 200 ? (string)$curl->getBody() : null,
            'etag' => $responseHeaders['etag'] ?? null,
            'mode' => $responseHeaders['x-citecue-mode'] ?? null,
        ];
    }

    /**
     * Lowercases header names and flattens duplicate values to the last one.
     *
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = end($value);
            }
            $normalized[strtolower((string)$name)] = (string)$value;
        }
        return $normalized;
    }

    /**
     * Strips the query string before logging (the "k" param is semi-sensitive).
     *
     * @param string $endpoint
     * @return string
     */
    private function redact(string $endpoint): string
    {
        $pos = strpos($endpoint, '?');
        return $pos === false ? $endpoint : substr($endpoint, 0, $pos);
    }

    /**
     * Human-readable failure reason for the admin connection test.
     *
     * @param int $status
     * @return string
     */
    private function describeFailure(int $status): string
    {
        if ($status === 401) {
            return 'Invalid API key — the Citecue API rejected the ck_live_… key.';
        }
        if ($status === 0) {
            return 'Could not reach the Citecue API (timeout, DNS or TLS failure).';
        }
        return 'Unexpected HTTP ' . $status . ' from the Citecue API.';
    }
}
