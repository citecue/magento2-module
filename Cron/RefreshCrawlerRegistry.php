<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Cron;

use Citecue\Delivery\Model\Api\Client;
use Citecue\Delivery\Model\Config;
use Citecue\Delivery\Model\CrawlerRegistry;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily refresh of the AI-crawler registry from the keyless
 * GET /api/delivery/v1/crawlers feed, so new crawlers are matched without a
 * module release. Skipped entirely unless the middleware is enabled for at
 * least one store. Failures are logged and harmless — matching falls back to
 * the previously cached feed (7-day TTL) or the bundled snapshot.
 */
class RefreshCrawlerRegistry
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var CrawlerRegistry
     */
    private $crawlerRegistry;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param Client $client
     * @param CrawlerRegistry $crawlerRegistry
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $client,
        CrawlerRegistry $crawlerRegistry,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->crawlerRegistry = $crawlerRegistry;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $enabledStoreId = null;
        foreach ($this->storeManager->getStores(true) as $store) {
            if ($this->config->isEnabled((int)$store->getId())) {
                $enabledStoreId = (int)$store->getId();
                break;
            }
        }
        if ($enabledStoreId === null) {
            return;
        }

        $registry = $this->client->fetchCrawlerRegistry($enabledStoreId);
        if ($registry === null) {
            $this->logger->warning('Citecue: crawler registry refresh failed; keeping the previous list.');
            return;
        }
        if ($this->crawlerRegistry->store($registry['crawlers'], $registry['version'])) {
            $this->logger->info(
                'Citecue: crawler registry refreshed (version ' . $registry['version'] . ', '
                . count($registry['crawlers']) . ' crawlers).'
            );
        } else {
            $this->logger->warning('Citecue: crawler registry feed was invalid; keeping the previous list.');
        }
    }
}
