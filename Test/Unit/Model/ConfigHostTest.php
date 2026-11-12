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
        $hosts = Config::normalizeHostList(
            "staging.citecue.com\nhttps://Self-Hosted.example.com/path\nother.example.com:8443"
        );
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
        // A public IP that is allowlisted is accepted.
        $publicAllowed = Config::normalizeHostList('8.8.8.8');
        $this->assertTrue(Config::isEndpointAllowed('https://8.8.8.8/api', $publicAllowed));
        // ...but not when it isn't on the list.
        $this->assertFalse(Config::isEndpointAllowed('https://8.8.4.4/api', Config::normalizeHostList('')));
        $this->assertFalse(Config::isEndpointAllowed('https://8.8.8.8/api', Config::normalizeHostList('')));
    }

    public function testRejectsBlockedRangesEvenIfAllowlisted(): void
    {
        $hosts = [
            '203.0.113.10', '198.51.100.7', '192.0.2.1', '100.100.100.200', '100.64.0.1', '0.0.0.0',
            '250.1.2.3', '224.0.0.1', '[::1]', '[fc00::1]', '[fd12:3456::1]', '[fe80::1]', '[fec0::1]',
            '[2001:db8::1]', '[::ffff:169.254.169.254]', '[2001::1]', '[2002::1]', '[64:ff9b::1]',
            '[100::1]', '[ff02::1]',
        ];
        foreach ($hosts as $host) {
            $allowed = Config::normalizeHostList($host);
            $this->assertFalse(Config::isEndpointAllowed('https://' . $host . '/api', $allowed), $host);
            // Also with the explicit allowlist containing the exact lowercase host.
            $this->assertFalse(Config::isEndpointAllowed('https://' . $host . '/api', [$host]), $host);
        }
    }

    public function testRejectsNumericFormHostsEvenIfAllowlisted(): void
    {
        foreach (['127.1', '2130706433', '0x7f.0.0.1'] as $host) {
            $this->assertFalse(Config::isEndpointAllowed('https://' . $host . '/api', [$host]), $host);
        }
    }

    public function testAllowsPublicBoundaryAddressesWhenAllowlisted(): void
    {
        foreach (['8.8.8.8', '100.63.255.255', '100.128.0.1', '198.17.255.255', '[2606:4700::1111]'] as $host) {
            $this->assertTrue(Config::isEndpointAllowed('https://' . $host . '/api', [$host]), $host);
        }
    }

    public function testIsBlockedIp(): void
    {
        foreach (['100.100.100.200', '203.0.113.10', 'fe80::1', '::ffff:169.254.169.254'] as $ip) {
            $this->assertTrue(Config::isBlockedIp($ip), $ip);
        }
        foreach (['8.8.8.8', '2606:4700::1111', '1.1.1.1'] as $ip) {
            $this->assertFalse(Config::isBlockedIp($ip), $ip);
        }
        $this->assertTrue(Config::isBlockedIp('not-an-ip'));
    }
}
