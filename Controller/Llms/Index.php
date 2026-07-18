<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Controller\Llms;

use Citecue\Delivery\Model\DeliveryService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;

/**
 * Serves the project's llms.txt (fetched from the Citecue delivery API and
 * locally cached) at the store's domain root. Public to every visitor — the
 * llms.txt convention is not crawler-gated. 404s when the store has no
 * servable llms.txt (not configured, disabled in either system, or the API
 * has no body for the project).
 */
class Index implements HttpGetActionInterface
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
     * @var HttpRequest
     */
    private $request;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param DeliveryService $deliveryService
     * @param RawFactory $rawFactory
     * @param HttpRequest $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        DeliveryService $deliveryService,
        RawFactory $rawFactory,
        HttpRequest $request,
        LoggerInterface $logger
    ) {
        $this->deliveryService = $deliveryService;
        $this->rawFactory = $rawFactory;
        $this->request = $request;
        $this->logger = $logger;
    }

    /**
     * @return Raw
     */
    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        try {
            $llms = $this->deliveryService->getLlmsTxt();
        } catch (\Throwable $e) {
            $this->logger->error('Citecue: llms.txt serving error: ' . $e->getMessage());
            $llms = null;
        }

        if ($llms === null) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
            $result->setHeader('Cache-Control', 'public, max-age=60', true);
            $result->setContents('Not found');
            return $result;
        }

        $etag = $llms['etag'];
        $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
        $result->setHeader('Cache-Control', 'public, max-age=300', true);
        if ($etag !== null && $etag !== '') {
            $result->setHeader('ETag', $etag, true);
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
                $result->setHttpResponseCode(304);
                $result->setContents('');
                return $result;
            }
        }
        $result->setHttpResponseCode(200);
        $result->setContents($llms['content']);
        return $result;
    }
}
