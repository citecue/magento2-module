<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Model;

use Citecue\Delivery\Model\Cache\Type as DeliveryCache;
use Citecue\Delivery\Model\CrawlerMatcher;
use Citecue\Delivery\Model\CrawlerRegistry;
use Citecue\Delivery\Model\DeliveryService;
use Citecue\Delivery\Model\PathMatcher;
use Citecue\Delivery\Test\Unit\Fake\FakeClient;
use Citecue\Delivery\Test\Unit\Fake\FakeConfig;
use Citecue\Delivery\Test\Unit\Fake\FakeRequest;
use Citecue\Delivery\Test\Unit\Fake\FakeStoreManager;
use Citecue\Delivery\Test\Unit\Fake\InMemoryCache;
use Citecue\Delivery\Test\Unit\Fake\NullTestLogger;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class DeliveryServiceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (compatible; GPTBot/1.2)';

    /**
     * @var Json
     */
    private $json;

    protected function setUp(): void
    {
        $this->json = new Json();
    }

    /**
     * @param array $config
     * @return array{service: DeliveryService, cache: InMemoryCache, client: FakeClient}
     */
    private function build(array $config = []): array
    {
        $cache = new InMemoryCache();
        $logger = new NullTestLogger();
        $client = new FakeClient();
        $service = new DeliveryService(
            new FakeConfig($config),
            new CrawlerRegistry($cache, $this->json, new CrawlerMatcher(), $logger),
            new PathMatcher(),
            $client,
            $cache,
            $this->json,
            new FakeStoreManager(),
            $logger
        );
        return ['service' => $service, 'cache' => $cache, 'client' => $client];
    }

    private function request(array $props = []): FakeRequest
    {
        return new FakeRequest($props + ['method' => 'GET', 'ua' => self::UA, 'uri' => '/pricing']);
    }

    private function html(string $body = '<x>', string $etag = 'W/"1"'): array
    {
        return [
            'status' => 200,
            'body' => $body,
            'etag' => $etag,
            'mode' => 'rewrite',
            'content_type' => 'text/html; charset=utf-8',
        ];
    }

    private function keysWithPrefix(InMemoryCache $cache, string $prefix): array
    {
        return array_values(array_filter($cache->keys(), function ($key) use ($prefix) {
            return strpos($key, $prefix) === 0;
        }));
    }

    /**
     * Rewrites stored_at of every live entry under the prefix to $age seconds ago.
     */
    private function ageEntries(InMemoryCache $cache, string $prefix, int $age): void
    {
        foreach ($this->keysWithPrefix($cache, $prefix) as $key) {
            $entry = $this->json->unserialize((string)$cache->getRaw($key));
            $entry['stored_at'] = time() - $age;
            $cache->putRaw($key, $this->json->serialize($entry), [DeliveryCache::STATE_TAG]);
        }
    }

    public function testServes200AndCachesEntry(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());

        $page = $env['service']->getCrawlerPage($this->request());

        $this->assertNotNull($page);
        $this->assertSame('<x>', $page['content']);
        $this->assertSame('rewrite', $page['mode']);
        $this->assertSame('text/html; charset=utf-8', $page['content_type']);
        $this->assertSame('text/html', $env['client']->calls[0]['accept']);
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_page_'));

        $epochKeys = $this->keysWithPrefix($env['cache'], 'citecue_epoch_');
        $this->assertCount(1, $epochKeys);
        $this->assertContains(DeliveryCache::STATE_TAG, $env['cache']->tagsOf($epochKeys[0]));
        $pageKeys = $this->keysWithPrefix($env['cache'], 'citecue_page_');
        $this->assertContains(DeliveryCache::STATE_TAG, $env['cache']->tagsOf($pageKeys[0]));
    }

    public function test404PurgesCachedEntry(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());
        $env['service']->getCrawlerPage($this->request());

        $env['client']->queueResponse(['status' => 404]);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
        $this->assertCount(0, $this->keysWithPrefix($env['cache'], 'citecue_page_'));
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_miss_'));

        $env['cache']->expirePrefix('citecue_miss_');
        $env['client']->queueResponse(['status' => 0]);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
    }

    public function test401RotatesEpochAndThrottles(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());
        $env['service']->getCrawlerPage($this->request());

        $env['client']->queueResponse(['status' => 401]);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
        $this->assertCount(0, $this->keysWithPrefix($env['cache'], 'citecue_api_down_'));
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_key_invalid_'));

        $calls = count($env['client']->calls);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
        $this->assertCount($calls, $env['client']->calls);
    }

    public function test401ThenOutageNeverServesOldBody(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html('<a>'));
        $env['service']->getCrawlerPage($this->request(['uri' => '/a']));
        $env['client']->queueResponse($this->html('<b>'));
        $env['service']->getCrawlerPage($this->request(['uri' => '/b']));

        $env['client']->queueResponse(['status' => 401]);
        $this->assertNull($env['service']->getCrawlerPage($this->request(['uri' => '/a'])));
        $env['cache']->expirePrefix('citecue_key_invalid_');

        $env['client']->queueResponse(['status' => 0]);
        $this->assertNull($env['service']->getCrawlerPage($this->request(['uri' => '/b'])));
    }

    public function testRecoveryAfter401(): void
    {
        $env = $this->build();
        $env['client']->queueResponse(['status' => 401]);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
        $env['cache']->expirePrefix('citecue_key_invalid_');

        $env['client']->queueResponse($this->html('<new>'));
        $page = $env['service']->getCrawlerPage($this->request());
        $this->assertNotNull($page);
        $this->assertSame('<new>', $page['content']);
    }

    public function testNewApiKeyIsTriedImmediately(): void
    {
        $env = $this->build();
        $env['client']->queueResponse(['status' => 401]);
        $env['service']->getCrawlerPage($this->request());

        // Same cache, different key: the invalid flag is keyed on the API key.
        $other = new DeliveryService(
            new FakeConfig(['apiKey' => 'ck_live_new']),
            new CrawlerRegistry($env['cache'], $this->json, new CrawlerMatcher(), new NullTestLogger()),
            new PathMatcher(),
            $env['client'],
            $env['cache'],
            $this->json,
            new FakeStoreManager(),
            new NullTestLogger()
        );
        $env['client']->queueResponse($this->html('<fresh>'));
        $page = $other->getCrawlerPage($this->request());
        $this->assertNotNull($page);
        $this->assertSame('<fresh>', $page['content']);
    }

    public function testStaleIfErrorCap(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());
        $env['service']->getCrawlerPage($this->request());

        // First transport error trips the breaker and serves the (young) entry.
        $env['client']->queueResponse(['status' => 0]);
        $page = $env['service']->getCrawlerPage($this->request());
        $this->assertNotNull($page);
        $this->assertSame('<x>', $page['content']);
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_api_down_'));

        $this->ageEntries($env['cache'], 'citecue_page_', 400);
        $env['cache']->expirePrefix('citecue_api_down_');
        $env['client']->queueResponse(['status' => 0]);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
    }

    public function testBreakerOpenRespectsCap(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());
        $env['service']->getCrawlerPage($this->request());
        $env['client']->queueResponse(['status' => 0]);
        $env['service']->getCrawlerPage($this->request());
        $calls = count($env['client']->calls);

        $page = $env['service']->getCrawlerPage($this->request());
        $this->assertNotNull($page);
        $this->assertSame('<x>', $page['content']);

        $this->ageEntries($env['cache'], 'citecue_page_', 400);
        $this->assertNull($env['service']->getCrawlerPage($this->request()));
        $this->assertCount($calls, $env['client']->calls);
    }

    public function testHeadServedPostNot(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());
        $this->assertNotNull($env['service']->getCrawlerPage($this->request(['method' => 'HEAD'])));

        $calls = count($env['client']->calls);
        $this->assertNull($env['service']->getCrawlerPage($this->request(['method' => 'POST'])));
        $this->assertCount($calls, $env['client']->calls);
    }

    public function testAcceptVariantsGetSeparateEntries(): void
    {
        $env = $this->build();
        $env['client']->queueResponse([
            'status' => 200,
            'body' => '# md',
            'etag' => 'W/"m"',
            'mode' => 'rewrite',
            'content_type' => 'text/markdown; charset=utf-8',
        ]);
        $md = $env['service']->getCrawlerPage($this->request(['accept' => 'text/markdown']));
        $env['client']->queueResponse($this->html());
        $html = $env['service']->getCrawlerPage($this->request(['accept' => 'text/html']));

        $this->assertCount(2, $env['client']->calls);
        $this->assertSame('text/markdown, text/html;q=0.9', $env['client']->calls[0]['accept']);
        $this->assertSame('text/html', $env['client']->calls[1]['accept']);
        $this->assertCount(2, $this->keysWithPrefix($env['cache'], 'citecue_page_'));
        $this->assertSame('text/markdown; charset=utf-8', $md['content_type']);
        $this->assertSame('# md', $md['content']);
        $this->assertSame('text/html; charset=utf-8', $html['content_type']);
        $this->assertSame('<x>', $html['content']);
    }

    public function testAcceptNegotiation(): void
    {
        $cases = [
            [null, 'text/html'],
            ['', 'text/html'],
            ['*/*', 'text/html'],
            ['text/html', 'text/html'],
            ['text/markdown', 'text/markdown, text/html;q=0.9'],
            ['text/markdown;q=0', 'text/html'],
            ['text/html, text/markdown', 'text/markdown, text/html;q=0.9'],
            ['text/markdown;q=0.5, text/html', 'text/html'],
            ['text/markdown, application/xhtml+xml;q=0.9, text/html;q=0.8', 'text/markdown, text/html;q=0.9'],
        ];
        foreach ($cases as [$accept, $expected]) {
            $env = $this->build();
            $env['client']->queueResponse(['status' => 404]);
            $env['service']->getCrawlerPage($this->request($accept === null ? [] : ['accept' => $accept]));
            $this->assertSame($expected, $env['client']->calls[0]['accept'], (string)$accept);
        }
    }

    public function testMarkdown404DoesNotPurgeHtmlEntry(): void
    {
        $env = $this->build();
        $env['client']->queueResponse($this->html());
        $env['service']->getCrawlerPage($this->request());

        $env['client']->queueResponse(['status' => 404]);
        $this->assertNull($env['service']->getCrawlerPage($this->request(['accept' => 'text/markdown'])));
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_page_'));

        // The markdown miss marker must not block the html variant.
        $env['client']->queueResponse(['status' => 304]);
        $page = $env['service']->getCrawlerPage($this->request());
        $this->assertNotNull($page);
        $this->assertSame('<x>', $page['content']);
        $this->assertSame('W/"1"', $env['client']->calls[2]['etag']);
    }

    public function testLegacyEntryWithoutContentTypeDefaults(): void
    {
        $env = $this->build(['localTtl' => 300]);
        $env['client']->queueResponse($this->html());
        $env['service']->getCrawlerPage($this->request());

        foreach ($this->keysWithPrefix($env['cache'], 'citecue_page_') as $key) {
            $entry = $this->json->unserialize((string)$env['cache']->getRaw($key));
            unset($entry['content_type']);
            $env['cache']->putRaw($key, $this->json->serialize($entry), [DeliveryCache::STATE_TAG]);
        }

        $page = $env['service']->getCrawlerPage($this->request());
        $this->assertNotNull($page);
        $this->assertSame('text/html; charset=UTF-8', $page['content_type']);
        $this->assertCount(1, $env['client']->calls);
    }

    public function testHostileContentTypeIsReplaced(): void
    {
        $env = $this->build();
        $env['client']->queueResponse([
            'status' => 200,
            'body' => '<x>',
            'content_type' => "application/json\r\nSet-Cookie: a=b",
        ]);
        $page = $env['service']->getCrawlerPage($this->request());
        $this->assertSame('text/html; charset=UTF-8', $page['content_type']);
    }

    public function testLlms404PurgesAnd401Throttles(): void
    {
        $env = $this->build();
        $env['client']->queueResponse(['status' => 200, 'body' => 'llms', 'etag' => 'W/"l"']);
        $first = $env['service']->getLlmsTxt();
        $this->assertSame('llms', $first['content']);

        $this->ageEntries($env['cache'], 'citecue_llms_', 400);
        $env['client']->queueResponse(['status' => 404]);
        $this->assertNull($env['service']->getLlmsTxt());
        $this->assertCount(0, $this->keysWithPrefix($env['cache'], 'citecue_llms_'));

        $env['cache']->expirePrefix('citecue_miss_');
        $env['client']->queueResponse(['status' => 200, 'body' => 'llms2', 'etag' => 'W/"l2"']);
        $this->assertNotNull($env['service']->getLlmsTxt());

        $this->ageEntries($env['cache'], 'citecue_llms_', 400);
        $env['client']->queueResponse(['status' => 401]);
        $this->assertNull($env['service']->getLlmsTxt());
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_key_invalid_'));
        $this->assertCount(0, $this->keysWithPrefix($env['cache'], 'citecue_api_down_'));

        $calls = count($env['client']->calls);
        $this->assertNull($env['service']->getLlmsTxt());
        $this->assertCount($calls, $env['client']->calls);

        $env['cache']->expirePrefix('citecue_key_invalid_');
        $env['client']->queueResponse(['status' => 0]);
        $this->assertNull($env['service']->getLlmsTxt());
        $this->assertSame(null, $env['client']->calls[$calls]['etag']);
    }

    public function testLlmsStaleCap(): void
    {
        $env = $this->build();
        $env['client']->queueResponse(['status' => 200, 'body' => 'llms', 'etag' => 'W/"l"']);
        $env['service']->getLlmsTxt();

        // Young entry: served from the fresh window without an API call.
        $this->assertNotNull($env['service']->getLlmsTxt());
        $this->assertCount(1, $env['client']->calls);

        $this->ageEntries($env['cache'], 'citecue_llms_', 400);
        $env['client']->queueResponse(['status' => 0]);
        $this->assertNull($env['service']->getLlmsTxt());
        $this->assertCount(1, $this->keysWithPrefix($env['cache'], 'citecue_api_down_'));

        // Breaker open + aged entry: still no stale body, and no API call.
        $calls = count($env['client']->calls);
        $this->assertNull($env['service']->getLlmsTxt());
        $this->assertCount($calls, $env['client']->calls);
    }
}
