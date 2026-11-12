<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Fake;

use Magento\Framework\App\Request\Http;

/**
 * Request double driven by a plain property map.
 */
class FakeRequest extends Http
{
    /**
     * @var array
     */
    private $props;

    /**
     * Intentionally skips the parent constructor.
     *
     * @param array $props method/ua/accept/path/scheme/host/uri
     */
    public function __construct(array $props = [])
    {
        $this->props = $props;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->props['method'] ?? 'GET';
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return string|false
     */
    public function getHeader($name, $default = false)
    {
        $key = ['user-agent' => 'ua', 'accept' => 'accept'][strtolower((string)$name)] ?? null;
        return $key !== null && isset($this->props[$key]) ? $this->props[$key] : false;
    }

    /**
     * @return string
     */
    public function getPathInfo()
    {
        return $this->props['path'] ?? '/pricing';
    }

    /**
     * @return string
     */
    public function getScheme()
    {
        return $this->props['scheme'] ?? 'https';
    }

    /**
     * @param bool $trimPort
     * @return string
     */
    public function getHttpHost($trimPort = true)
    {
        return $this->props['host'] ?? 'www.example.com';
    }

    /**
     * @return string
     */
    public function getRequestUri()
    {
        return $this->props['uri'] ?? '/pricing';
    }
}
