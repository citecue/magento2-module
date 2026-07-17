<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Controller\Adminhtml\System\Config;

use Citecue\Delivery\Model\Api\Client;
use Citecue\Delivery\Model\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * AJAX endpoint behind the "Test Connection" button in the module's system
 * configuration: calls GET /api/delivery/v2/config with the entered (or
 * saved) API key and returns the organization's delivery projects, so the
 * merchant can copy the right project public key and see whether delivery
 * is enabled for it in the Citecue dashboard.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Citecue_Delivery::config';

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Client $client
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Client $client,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->client = $client;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * @return JsonResult
     */
    public function execute(): JsonResult
    {
        $result = $this->jsonFactory->create();

        // An obscured field posts its masked placeholder ("******") when the
        // admin didn't retype the key — fall back to the saved one then.
        $postedKey = trim((string)$this->getRequest()->getParam('api_key', ''));
        if ($postedKey === '' || preg_match('/^\*+$/', $postedKey)) {
            $postedKey = null;
        }
        $postedBaseUrl = trim((string)$this->getRequest()->getParam('base_url', ''));
        if ($postedBaseUrl === '') {
            $postedBaseUrl = null;
        }

        // Resolve the store scope of the config page the button was clicked
        // from, so a masked (not-retyped) key or the saved public key is read
        // at the same scope the admin is editing — not always the default.
        $storeId = $this->resolveScopeStoreId();

        try {
            $response = $this->client->fetchConfig($postedKey, $postedBaseUrl, $storeId);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => (string)__('Connection test failed unexpectedly. Check var/log for details.'),
            ]);
        }

        if ($response['projects'] === null) {
            return $result->setData([
                'success' => false,
                'message' => $response['error'] ?? (string)__('Connection failed.'),
            ]);
        }

        $configuredPublicKey = trim((string)$this->getRequest()->getParam('public_key', ''))
            ?: $this->config->getPublicKey($storeId);

        $projects = [];
        foreach ($response['projects'] as $project) {
            if (!is_array($project)) {
                continue;
            }
            $projects[] = [
                'publicKey' => (string)($project['publicKey'] ?? ''),
                'domain' => (string)($project['domain'] ?? ''),
                'enabled' => !empty($project['enabled']),
                'serveLlmsTxt' => !empty($project['serveLlmsTxt']),
                'current' => $configuredPublicKey !== ''
                    && $configuredPublicKey === (string)($project['publicKey'] ?? ''),
            ];
        }

        return $result->setData([
            'success' => true,
            'message' => (string)__('Connection OK — %1 project(s) found.', count($projects)),
            'projects' => $projects,
        ]);
    }

    /**
     * Maps the admin system-config scope (the `store`/`website` params the
     * config page posts) to a store id the store-scoped Config getters can
     * read. A website scope resolves to that website's default store (whose
     * effective value inherits the website-scoped config); default scope
     * returns null. Any resolution failure degrades to null (default scope).
     *
     * @return int|null
     */
    private function resolveScopeStoreId(): ?int
    {
        $storeParam = $this->getRequest()->getParam('store');
        if ($storeParam !== null && $storeParam !== '') {
            return (int)$storeParam;
        }
        $websiteParam = $this->getRequest()->getParam('website');
        if ($websiteParam !== null && $websiteParam !== '') {
            try {
                $defaultStore = $this->storeManager->getWebsite($websiteParam)->getDefaultStore();
                return $defaultStore ? (int)$defaultStore->getId() : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }
}
