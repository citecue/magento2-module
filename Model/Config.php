<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for all citecue_delivery/* configuration. All getters are
 * store-scoped so multi-store setups can map different store views to
 * different Citecue projects (each store's domain is its own project).
 */
class Config
{
    public const XML_PATH_ENABLED = 'citecue_delivery/general/enabled';
    public const XML_PATH_API_KEY = 'citecue_delivery/general/api_key';
    public const XML_PATH_PUBLIC_KEY = 'citecue_delivery/general/public_key';
    public const XML_PATH_SERVE_LLMS_TXT = 'citecue_delivery/general/serve_llms_txt';
    public const XML_PATH_BASE_URL = 'citecue_delivery/advanced/base_url';
    public const XML_PATH_ALLOWED_HOSTS = 'citecue_delivery/advanced/allowed_hosts';
    public const XML_PATH_TIMEOUT = 'citecue_delivery/advanced/timeout';
    public const XML_PATH_CONNECT_TIMEOUT = 'citecue_delivery/advanced/connect_timeout';
    public const XML_PATH_LOCAL_CACHE_TTL = 'citecue_delivery/advanced/local_cache_ttl';
    public const XML_PATH_EXCLUDED_PATHS = 'citecue_delivery/advanced/excluded_paths';
    public const XML_PATH_DEBUG_LOGGING = 'citecue_delivery/advanced/debug_logging';

    public const DEFAULT_BASE_URL = 'https://app.citecue.com';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the middleware is enabled for the given store.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The decrypted ck_live_… organization API key, or empty string.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getApiKey($storeId = null): string
    {
        $encrypted = (string)$this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($encrypted === '') {
            return '';
        }
        try {
            return trim((string)$this->encryptor->decrypt($encrypted));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The Citecue project public key ("k" query parameter), or empty string.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getPublicKey($storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_PUBLIC_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Enabled AND both keys present — the minimum needed to call the API.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isConfigured($storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->getApiKey($storeId) !== ''
            && $this->getPublicKey($storeId) !== '';
    }

    /**
     * Whether /llms.txt should be served at this store's domain root.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isServeLlmsTxt($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SERVE_LLMS_TXT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Citecue API base URL without a trailing slash.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getBaseUrl($storeId = null): string
    {
        $url = trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        // Defense in depth: the save-time backend model already enforces the
        // allowlist, but a value injected by other means (direct DB /
        // setup:config:set) must never send credentialed requests to a
        // non-https or non-allowlisted host — fall back to the trusted default.
        if ($url === '' || !$this->isAllowedBaseUrl($url, $storeId)) {
            $url = self::DEFAULT_BASE_URL;
        }
        return rtrim($url, '/');
    }

    /**
     * The saved Base URL when it is non-empty yet not allowlisted.
     *
     * Runtime requests fall back to the default host in that case; this lets
     * the admin UI surface what getBaseUrl() silently corrects.
     *
     * @param int|string|null $storeId
     * @return string|null
     */
    public function getRejectedBaseUrl($storeId = null): ?string
    {
        $url = trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($url !== '' && !$this->isAllowedBaseUrl($url, $storeId)) {
            return $url;
        }
        return null;
    }

    /**
     * The admin-configured trusted API hosts.
     *
     * The built-in default host is always included. Credentialed delivery
     * requests may only target these hosts, so a config editor can't redirect
     * the org API key to an internal or attacker-controlled endpoint.
     *
     * @param int|string|null $storeId
     * @return string[]
     */
    public function getAllowedHosts($storeId = null): array
    {
        return self::normalizeHostList((string)$this->scopeConfig->getValue(
            self::XML_PATH_ALLOWED_HOSTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Whether a base URL may receive credentialed requests at this scope.
     *
     * @param string $url
     * @param int|string|null $storeId
     * @return bool
     */
    public function isAllowedBaseUrl(string $url, $storeId = null): bool
    {
        return self::isEndpointAllowed($url, $this->getAllowedHosts($storeId));
    }

    // The helpers below are deliberately pure, dependency-free statics:
    // they implement the SSRF host-allowlist rules once, are unit-tested
    // without the framework, and are shared with the save-time base-URL
    // validator. parse_url() is the only stdlib URL parser; no framework
    // equivalent is equally side-effect-free.
    // phpcs:disable Magento2.Functions.StaticFunction, Magento2.Functions.DiscouragedFunction

    /**
     * Address ranges that may never be a delivery endpoint, as 'address/prefix'.
     */
    private const BLOCKED_CIDRS = [
        // IPv4: "this" network, loopback, RFC1918, CGNAT, link-local.
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        // IPv4: IETF protocol assignments, documentation (TEST-NET-1..3), RFC1918, benchmarking.
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        // IPv4: multicast, reserved.
        '224.0.0.0/4',
        '240.0.0.0/4',
        // IPv6: unspecified, loopback, IPv4-mapped, NAT64, discard, Teredo.
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001::/32',
        // IPv6: documentation, 6to4, ULA, link-local, deprecated site-local, multicast.
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * Parses a newline/comma-separated host list into normalized hostnames.
     *
     * The result is a unique, lowercased set. The built-in default host is
     * always present so the module never locks itself out of its own default
     * endpoint. Each entry may be a bare host or a full URL (the host is
     * extracted). Pure — unit-tested and reused by the base-URL backend
     * validator.
     *
     * @param string $raw
     * @return string[]
     */
    public static function normalizeHostList(string $raw): array
    {
        $hosts = [];
        $default = parse_url(self::DEFAULT_BASE_URL, PHP_URL_HOST);
        if (is_string($default) && $default !== '') {
            $hosts[strtolower($default)] = true;
        }
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $host = self::extractHost(trim($line));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }

    /**
     * Whether an absolute URL is a valid, allowed delivery endpoint.
     *
     * It must be https, its host must be in $allowedHosts, and IP-literal
     * hosts inside the explicit BLOCKED_CIDRS denylist (private, loopback,
     * link-local, CGNAT, documentation, multicast, reserved, ...) are always
     * rejected even if allowlisted. The denylist is version-independent, so
     * PHP 8.4 and 8.5 behave identically. Numeric-form hosts such as 127.1,
     * 2130706433 or 0x7f.0.0.1 are rejected too. Pure.
     *
     * @param string $url
     * @param string[] $allowedHosts
     * @return bool
     */
    public static function isEndpointAllowed(string $url, array $allowedHosts): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        // Hard-block blocked IP-literal hosts regardless of the allowlist.
        // Bracketed IPv6 literals are unwrapped first.
        $ipCandidate = trim($host, '[]');
        if (filter_var($ipCandidate, FILTER_VALIDATE_IP) !== false) {
            if (self::isBlockedIp($ipCandidate)) {
                return false;
            }
        } elseif (preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host) === 1) {
            // Numeric-form hosts (127.1, 2130706433, 0x7f.0.0.1) are not canonical IPs
            // but resolvers accept them, so they are never allowlistable.
            return false;
        }
        return in_array($host, $allowedHosts, true);
    }

    /**
     * Whether an IP literal falls inside a blocked range (unparseable counts as blocked).
     *
     * @param string $ip
     * @return bool
     */
    public static function isBlockedIp(string $ip): bool
    {
        // filter_var first: inet_pton() warns on malformed input, and anything
        // that is not a canonical IP literal counts as blocked anyway.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::cidrMatch($bin, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a packed address lies in the CIDR range (address families must match).
     *
     * @param string $binIp
     * @param string $cidr
     * @return bool
     */
    private static function cidrMatch(string $binIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $binNet = inet_pton($net);
        if ($binNet === false || strlen($binNet) !== strlen($binIp)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($binIp, 0, $bytes) !== substr($binNet, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($binIp[$bytes]) & $mask) === (ord($binNet[$bytes]) & $mask);
    }

    /**
     * Extracts a normalized hostname from a bare host or a full URL entry.
     *
     * @param string $entry
     * @return string
     */
    private static function extractHost(string $entry): string
    {
        if ($entry === '') {
            return '';
        }
        $host = strpos($entry, '//') !== false ? (parse_url($entry, PHP_URL_HOST) ?: '') : $entry;
        $host = strtolower(trim((string)$host));
        // Drop an accidental trailing path.
        $slash = strpos($host, '/');
        if ($slash !== false) {
            $host = substr($host, 0, $slash);
        }
        // Strip a host:port suffix, but not a bare/bracketed IPv6 literal.
        if ($host !== '' && $host[0] !== '[' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $colon = strrpos($host, ':');
            if ($colon !== false) {
                $host = substr($host, 0, $colon);
            }
        }
        return $host;
    }

    // phpcs:enable Magento2.Functions.StaticFunction, Magento2.Functions.DiscouragedFunction

    /**
     * Total request timeout in seconds (minimum 1).
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getTimeout($storeId = null): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_TIMEOUT, ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, $value ?: 3);
    }

    /**
     * Connect timeout in seconds (minimum 1).
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getConnectTimeout($storeId = null): int
    {
        $value = (int)$this->scopeConfig->getValue(
            self::XML_PATH_CONNECT_TIMEOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return max(1, $value ?: 2);
    }

    /**
     * Seconds a locally cached optimized page may be served unrevalidated.
     *
     * 0 = always revalidate against the API.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getLocalCacheTtl($storeId = null): int
    {
        return max(
            0,
            (int)$this->scopeConfig->getValue(self::XML_PATH_LOCAL_CACHE_TTL, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    /**
     * Normalized excluded path prefixes (lowercase, no surrounding slashes).
     *
     * @param int|string|null $storeId
     * @return string[]
     */
    public function getExcludedPathPrefixes($storeId = null): array
    {
        $raw = (string)$this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_PATHS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $prefixes = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = strtolower(trim(trim($line), '/'));
            if ($line !== '') {
                $prefixes[] = $line;
            }
        }
        return $prefixes;
    }

    /**
     * Whether verbose debug logging is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isDebugLogging($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG_LOGGING, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
