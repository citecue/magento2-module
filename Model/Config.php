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
        // Defense in depth: the save-time backend model already enforces https,
        // but a value injected by other means (direct DB / setup:config:set)
        // must never downgrade credentialed requests off TLS — fall back to the
        // trusted default instead.
        $scheme = $url !== '' ? parse_url($url, PHP_URL_SCHEME) : null;
        if ($url === '' || !is_string($scheme) || strtolower($scheme) !== 'https') {
            $url = self::DEFAULT_BASE_URL;
        }
        return rtrim($url, '/');
    }

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
     * Seconds a locally cached optimized page may be served without
     * revalidating against the API. 0 = always revalidate.
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
