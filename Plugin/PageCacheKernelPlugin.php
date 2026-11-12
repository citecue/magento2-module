<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Plugin;

use Citecue\Delivery\Model\DeliveryService;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Forces a builtin full-page-cache MISS for detected AI crawlers, so their
 * requests always reach the Citecue middleware (or a fresh render on
 * passthrough) instead of being answered from an FPC entry that was stored
 * for regular visitors. Defense in depth alongside the front-controller
 * plugin's -100 sortOrder — correctness must not depend on plugin ordering.
 *
 * No poisoning in the other direction either: passthrough pages rendered for
 * crawlers are byte-identical to any anonymous render (Magento does not vary
 * on UA), and the middleware's own served responses are private/no-store, so
 * Kernel::process never stores them.
 */
class PageCacheKernelPlugin
{
    /**
     * @var DeliveryService
     */
    private $deliveryService;

    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @param DeliveryService $deliveryService
     * @param HttpRequest $request
     */
    public function __construct(
        DeliveryService $deliveryService,
        HttpRequest $request
    ) {
        $this->deliveryService = $deliveryService;
        $this->request = $request;
    }

    /**
     * Forces a full-page-cache miss for detected AI crawlers.
     *
     * @param Kernel $subject
     * @param callable $proceed
     * @return \Magento\Framework\App\Response\Http|false
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundLoad(Kernel $subject, callable $proceed)
    {
        try {
            if ($this->deliveryService->isCrawlerRequest($this->request)) {
                return false;
            }
        } catch (\Throwable $e) {
            // Fall through to normal FPC behavior on any error.
            return $proceed();
        }
        return $proceed();
    }
}
