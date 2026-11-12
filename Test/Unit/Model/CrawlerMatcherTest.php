<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Model;

use Citecue\Delivery\Model\CrawlerMatcher;
use PHPUnit\Framework\TestCase;

class CrawlerMatcherTest extends TestCase
{
    private const CRAWLERS = [
        ['id' => 'gptbot', 'token' => 'GPTBot', 'fetchesPages' => true],
        ['id' => 'chatgpt-user', 'token' => 'ChatGPT-User', 'fetchesPages' => true],
        ['id' => 'claudebot', 'token' => 'ClaudeBot', 'fetchesPages' => true],
        ['id' => 'google-extended', 'token' => 'Google-Extended', 'fetchesPages' => false],
        ['id' => 'googleother', 'token' => 'GoogleOther', 'fetchesPages' => true],
        ['id' => 'perplexity-user', 'token' => 'Perplexity-User', 'fetchesPages' => true],
    ];

    /**
     * @var CrawlerMatcher
     */
    private $matcher;

    protected function setUp(): void
    {
        $this->matcher = new CrawlerMatcher();
    }

    public function testMatchesKnownCrawlerCaseInsensitively(): void
    {
        $match = $this->matcher->matchServable(
            self::CRAWLERS,
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; gptbot/1.2; +https://openai.com/gptbot'
        );
        $this->assertNotNull($match);
        $this->assertSame('gptbot', $match['id']);
    }

    public function testLongestTokenWins(): void
    {
        // UA contains both "GPTBot" and "ChatGPT-User"-adjacent tokens; the longer token must win.
        $match = $this->matcher->matchServable(
            self::CRAWLERS,
            'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot) GPTBot'
        );
        $this->assertNotNull($match);
        $this->assertSame('chatgpt-user', $match['id']);
    }

    public function testRobotsOnlyTokenIsNeverServable(): void
    {
        $this->assertNull($this->matcher->matchServable(self::CRAWLERS, 'Google-Extended'));
        // ...but plain match() still sees it (upstream parity).
        $match = $this->matcher->match(self::CRAWLERS, 'Google-Extended');
        $this->assertNotNull($match);
        $this->assertSame('google-extended', $match['id']);
    }

    public function testRegularBrowserDoesNotMatch(): void
    {
        $this->assertNull($this->matcher->matchServable(
            self::CRAWLERS,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/126.0 Safari/537.36'
        ));
    }

    public function testGooglebotDoesNotMatch(): void
    {
        $this->assertNull($this->matcher->matchServable(
            self::CRAWLERS,
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ));
    }

    public function testEmptyAndNullUserAgent(): void
    {
        $this->assertNull($this->matcher->matchServable(self::CRAWLERS, null));
        $this->assertNull($this->matcher->matchServable(self::CRAWLERS, ''));
    }
}
