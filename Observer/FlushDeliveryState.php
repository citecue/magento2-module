<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Observer;

use Citecue\Delivery\Model\Cache\Type as DeliveryCache;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Flushes the module's delivery state (cached pages, llms.txt, miss markers,
 * circuit-breaker and key flags, the key epoch) when the Citecue Delivery
 * config section is saved, so a corrected API key or base URL takes effect
 * immediately instead of waiting out a 60s breaker window. The clean is scoped
 * by Cache\Type::STATE_TAG so the cached crawler registry survives; failures
 * never break the admin save.
 */
class FlushDeliveryState implements ObserverInterface
{
    /**
     * @var DeliveryCache
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param DeliveryCache $cache
     * @param LoggerInterface $logger
     */
    public function __construct(DeliveryCache $cache, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Cleans the delivery state; logs (never throws) on failure.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->cache->cleanDeliveryState();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Citecue: failed to flush the delivery cache after a config save: ' . $e->getMessage()
            );
        }
    }
}
