<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates the Citecue API base URL on save. The field exists so Citecue
 * support can point a store at a staging/self-hosted endpoint, so a strict
 * single-host allowlist is intentionally not enforced — but the org API key
 * is sent to this host with Bearer auth, so an https scheme and a well-formed
 * host are required to keep credentials on TLS and block non-http(s) SSRF
 * sinks (file://, gopher://, …). An empty value clears the override and the
 * module falls back to the built-in default.
 */
class BaseUrl extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            return parent::beforeSave();
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($scheme) || strtolower($scheme) !== 'https' || !$host) {
            throw new LocalizedException(
                __('The Citecue API Base URL must be a valid https:// URL (e.g. https://app.citecue.com).')
            );
        }

        $this->setValue(rtrim($value, '/'));
        return parent::beforeSave();
    }
}
