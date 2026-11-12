<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Fake;

use Citecue\Delivery\Model\Config;

/**
 * Config with in-memory overrides; the scope config and encryptor are never touched.
 */
class FakeConfig extends Config
{
    /**
     * @var array
     */
    private $values;

    /**
     * Intentionally skips the parent constructor.
     *
     * @param array $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function isConfigured($storeId = null): bool
    {
        return (bool)($this->values['configured'] ?? true);
    }

    /**
     * @param int|string|null $storeId
     * @return string
     */
    public function getApiKey($storeId = null): string
    {
        return (string)($this->values['apiKey'] ?? 'ck_live_test');
    }

    /**
     * @param int|string|null $storeId
     * @return string
     */
    public function getPublicKey($storeId = null): string
    {
        return (string)($this->values['publicKey'] ?? 'cc_pub_test');
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function isServeLlmsTxt($storeId = null): bool
    {
        return (bool)($this->values['serveLlms'] ?? true);
    }

    /**
     * @param int|string|null $storeId
     * @return int
     */
    public function getLocalCacheTtl($storeId = null): int
    {
        return (int)($this->values['localTtl'] ?? 0);
    }

    /**
     * @param int|string|null $storeId
     * @return string[]
     */
    public function getExcludedPathPrefixes($storeId = null): array
    {
        return (array)($this->values['excluded'] ?? []);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function isDebugLogging($storeId = null): bool
    {
        return (bool)($this->values['debug'] ?? false);
    }

    /**
     * @param int|string|null $storeId
     * @return string|null
     */
    public function getRejectedBaseUrl($storeId = null): ?string
    {
        $url = $this->values['rejectedBaseUrl'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }
}
