<?php
// phpcs:ignoreFile -- declares framework interface stubs for the no-framework test runner
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Event {
    if (!\interface_exists('Magento\Framework\Event\ObserverInterface')) {
        interface ObserverInterface
        {
            public function execute(Observer $observer);
        }
    }
    if (!\class_exists('Magento\Framework\Event\Observer')) {
        class Observer
        {
        }
    }
}

namespace Citecue\Delivery\Test\Unit\Observer {
    use Citecue\Delivery\Model\Cache\Type as DeliveryCache;
    use Citecue\Delivery\Model\CrawlerMatcher;
    use Citecue\Delivery\Model\CrawlerRegistry;
    use Citecue\Delivery\Model\DeliveryService;
    use Citecue\Delivery\Model\PathMatcher;
    use Citecue\Delivery\Observer\FlushDeliveryState;
    use Citecue\Delivery\Test\Unit\Fake\FakeClient;
    use Citecue\Delivery\Test\Unit\Fake\FakeConfig;
    use Citecue\Delivery\Test\Unit\Fake\FakeRequest;
    use Citecue\Delivery\Test\Unit\Fake\FakeStoreManager;
    use Citecue\Delivery\Test\Unit\Fake\InMemoryCache;
    use Citecue\Delivery\Test\Unit\Fake\NullTestLogger;
    use Magento\Framework\Event\Observer;
    use Magento\Framework\Serialize\Serializer\Json;
    use PHPUnit\Framework\TestCase;

    class FlushDeliveryStateTest extends TestCase
    {
        /**
         * @return string
         */
        private function registryId(): string
        {
            // The id is a private constant; read it reflectively so the test tracks the source of truth.
            return (string)(new \ReflectionClassConstant(CrawlerRegistry::class, 'CACHE_KEY'))->getValue();
        }

        public function testCleansStateButKeepsRegistry(): void
        {
            $cache = new InMemoryCache();
            $tags = [DeliveryCache::STATE_TAG];
            $cache->putRaw('citecue_page_x', '{"content":"old"}', $tags);
            $cache->putRaw('citecue_key_invalid_x', '1', $tags);
            $cache->putRaw('citecue_api_down_x', '1', $tags);
            $cache->putRaw($this->registryId(), '[]', []);

            (new FlushDeliveryState($cache, new NullTestLogger()))->execute(new Observer());

            $this->assertFalse($cache->load('citecue_page_x'));
            $this->assertFalse($cache->load('citecue_key_invalid_x'));
            $this->assertFalse($cache->load('citecue_api_down_x'));
            $this->assertSame('[]', $cache->load($this->registryId()));
        }

        public function testCleanFailureDoesNotPropagate(): void
        {
            $cache = new class extends InMemoryCache {
                public function clean($mode = 'all', array $tags = [])
                {
                    throw new \RuntimeException('backend down');
                }
            };
            $logger = new NullTestLogger();

            (new FlushDeliveryState($cache, $logger))->execute(new Observer());

            $this->assertCount(1, $logger->records);
            $this->assertSame('warning', $logger->records[0]['level']);
            $this->assertStringContainsString('backend down', $logger->records[0]['message']);
        }

        public function testFlushedFlagAllowsImmediateRetry(): void
        {
            $json = new Json();
            $cache = new InMemoryCache();
            $logger = new NullTestLogger();
            $client = new FakeClient();
            $service = new DeliveryService(
                new FakeConfig([]),
                new CrawlerRegistry($cache, $json, new CrawlerMatcher(), $logger),
                new PathMatcher(),
                $client,
                $cache,
                $json,
                new FakeStoreManager(),
                $logger
            );
            $request = function () {
                return new FakeRequest([
                    'method' => 'GET',
                    'ua' => 'Mozilla/5.0 (compatible; GPTBot/1.2)',
                    'uri' => '/pricing',
                ]);
            };

            $client->queueResponse(['status' => 401]);
            $this->assertNull($service->getCrawlerPage($request()));
            $this->assertCount(1, $client->calls);

            $this->assertNull($service->getCrawlerPage($request()));
            $this->assertCount(1, $client->calls);

            (new FlushDeliveryState($cache, $logger))->execute(new Observer());

            $client->queueResponse([
                'status' => 200,
                'body' => '<fresh>',
                'etag' => 'W/"1"',
                'mode' => 'rewrite',
                'content_type' => 'text/html; charset=utf-8',
            ]);
            $page = $service->getCrawlerPage($request());
            $this->assertCount(2, $client->calls);
            $this->assertNotNull($page);
            $this->assertSame('<fresh>', $page['content']);
        }
    }
}
