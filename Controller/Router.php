<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Controller;

use Citecue\Delivery\Model\Config;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RouterInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Matches the literal /llms.txt path (llmstxt.org convention — same pattern
 * as Magento_Robots' /robots.txt router) and forwards it to
 * citecue/llms/index. Returns null for everything else so the router chain
 * continues untouched.
 */
class Router implements RouterInterface
{
    /**
     * @var ActionFactory
     */
    private $actionFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param ActionFactory $actionFactory
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ActionFactory $actionFactory,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->actionFactory = $actionFactory;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }
        if (trim((string)$request->getPathInfo(), '/') !== 'llms.txt') {
            return null;
        }
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$this->config->isConfigured($storeId) || !$this->config->isServeLlmsTxt($storeId)) {
            return null;
        }

        $request->setModuleName('citecue')
            ->setControllerName('llms')
            ->setActionName('index');

        return $this->actionFactory->create(Forward::class);
    }
}
