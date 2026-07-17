<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Dedicated cache type for everything the delivery middleware stores locally:
 * optimized page bodies (+ ETags), llms.txt, miss sentinels, the API-down
 * circuit-breaker flag and the remote AI-crawler registry. Flushable from
 * Admin > Cache Management as "Citecue Delivery".
 */
class Type extends TagScope
{
    public const TYPE_IDENTIFIER = 'citecue_delivery';
    public const CACHE_TAG = 'CITECUE_DELIVERY';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
