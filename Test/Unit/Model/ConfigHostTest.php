<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Model;

use Citecue\Delivery\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure host-allowlist helpers that back the base-URL SSRF guard.
 */
class ConfigHostTest extends TestCase
{
    public function testDefaultHostIsAlwaysIncluded(): void
    {
        $this->assertContains('app.citecue.com', Config::normalizeHostList(''));
    }

    public function testParsesLinesUrlsAndPorts(): void
    {
        $hosts = Config::normalizeHostList("staging.citecue.com\nhttps://Self-Hosted.example.com/path\nother.example.com:8443");
        $this->assertContains('app.citecue.com', $hosts);
        $this->assertContains('staging.citecue.com', $hosts);
        $this->assertContains('self-hosted.example.com', $hosts);
        $this->assertContains('other.example.com', $hosts);
    }

    public function testAllowsDefaultAndConfiguredHttpsHosts(): void
    {
        $allowed = Config::normalizeHostList("staging.citecue.com");
        $this->assertTrue(Config::isEndpointAllowed('https://app.citecue.com/api/delivery/v2/page?k=x&u=y', $allowed));
        $this->assertTrue(Config::isEndpointAllowed('https://staging.citecue.com/api/delivery/v2/config', $allowed));
    }

    public function testRejectsNonAllowlistedHost(): void
    {
        $allowed = Config::normalizeHostList('');
        $this->assertFalse(Config::isEndpointAllowed('https://evil.example.com/api/delivery/v2/config', $allowed));
        $this->assertFalse(Config::isEndpointAllowed('https://localhost/api', $allowed));
    }

    public function testRejectsNonHttps(): void
    {
        $allowed = Config::normalizeHostList('app.citecue.com');
        $this->assertFalse(Config::isEndpointAllowed('http://app.citecue.com/api', $allowed));
        $this->assertFalse(Config::isEndpointAllowed('file:///etc/passwd', $allowed));
    }

    public function testRejectsPrivateAndReservedIpLiteralsEvenIfAllowlisted(): void
    {
        // Explicitly allowlisting an internal IP must still be rejected.
        $allowed = Config::normalizeHostList("169.254.169.254\n127.0.0.1\n10.0.0.5\n192.168.1.1");
        $this->assertFalse(Config::isEndpointAllowed('https://169.254.169.254/latest/meta-data/', $allowed));
        $this->assertFalse(Config::isEndpointAllowed('https://127.0.0.1/api', $allowed));
        $this->assertFalse(Config::isEndpointAllowed('https://10.0.0.5/api', $allowed));
        $this->assertFalse(Config::isEndpointAllowed('https://192.168.1.1/api', $allowed));
    }

    public function testAllowsPublicIpLiteralOnlyWhenAllowlisted(): void
    {
        $allowed = Config::normalizeHostList('203.0.113.10');
        // 203.0.113.0/24 is TEST-NET-3 (reserved) → still rejected.
        $this->assertFalse(Config::isEndpointAllowed('https://203.0.113.10/api', $allowed));
        // A genuinely public IP that is allowlisted is accepted.
        $publicAllowed = Config::normalizeHostList('8.8.8.8');
        $this->assertTrue(Config::isEndpointAllowed('https://8.8.8.8/api', $publicAllowed));
        // ...but not when it isn't on the list.
        $this->assertFalse(Config::isEndpointAllowed('https://8.8.8.8/api', Config::normalizeHostList('')));
    }
}
