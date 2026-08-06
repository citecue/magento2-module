<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Plugin;

use Citecue\Delivery\Model\DeliveryService;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;

/**
 * The middleware entry point: wraps FrontControllerInterface::dispatch as the
 * outermost around plugin (sortOrder -100, before Magento_PageCache) and
 * short-circuits routing entirely when Citecue has an optimized version of
 * the requested page for the calling AI crawler. Everyone else — and every
 * failure path — falls through to $proceed() untouched.
 *
 * Served responses carry Cache-Control: private, no-store so neither the
 * builtin FPC (which only stores public+s-maxage responses) nor
 * Varnish/CDNs ever cache the crawler-only variant for regular visitors.
 */
class FrontControllerPlugin
{
    /**
     * @var DeliveryService
     */
    private $deliveryService;

    /**
     * @var RawFactory
     */
    private $rawFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param DeliveryService $deliveryService
     * @param RawFactory $rawFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        DeliveryService $deliveryService,
        RawFactory $rawFactory,
        LoggerInterface $logger
    ) {
        $this->deliveryService = $deliveryService;
        $this->rawFactory = $rawFactory;
        $this->logger = $logger;
    }

    /**
     * Serves the Citecue-optimized page to a detected AI crawler, else proceeds.
     *
     * @param FrontControllerInterface $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ) {
        try {
            if ($request instanceof HttpRequest) {
                $page = $this->deliveryService->getCrawlerPage($request);
                if ($page !== null) {
                    $result = $this->rawFactory->create();
                    $result->setHttpResponseCode(200);
                    $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
                    $result->setHeader('Cache-Control', 'private, no-store', true);
                    $result->setHeader('X-Citecue-Mode', $page['mode'], true);
                    $result->setHeader('X-Citecue-Delivery', 'magento', true);
                    $result->setContents($page['content']);
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            // Fail open: the middleware must never take the storefront down.
            $this->logger->error('Citecue: delivery middleware error, passing through: ' . $e->getMessage());
        }
        return $proceed($request);
    }
}
