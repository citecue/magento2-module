<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model\Config\Backend;

use Citecue\Delivery\Model\Config as CitecueConfig;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Validates the Citecue API base URL on save: it must be a well-formed
 * https:// URL whose host is one of the configured Allowed API Hosts (the
 * default app.citecue.com is always allowed), and must not be a
 * private/loopback/link-local address. This keeps the org API key — sent to
 * this host with Bearer auth — from being redirected to an internal or
 * attacker-controlled endpoint. An empty value clears the override and the
 * module falls back to the built-in default.
 */
class BaseUrl extends Value
{
    /**
     * @var CitecueConfig
     */
    private $citecueConfig;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param CitecueConfig $citecueConfig
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        CitecueConfig $citecueConfig,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->citecueConfig = $citecueConfig;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validates the base URL against the https + allowed-host rules on save.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            // Persist the cleared value (not the original whitespace) so an
            // empty override restores the built-in default endpoint.
            $this->setValue('');
            return parent::beforeSave();
        }

        // Prefer the Allowed API Hosts being submitted alongside this field in
        // the same save, so adding a host and pointing the base URL at it in one
        // save works; otherwise fall back to the currently saved allowlist.
        $pendingAllowed = $this->getFieldsetDataValue('allowed_hosts');
        $allowed = $pendingAllowed !== null
            ? CitecueConfig::normalizeHostList((string)$pendingAllowed)
            : $this->citecueConfig->getAllowedHosts();

        if (!CitecueConfig::isEndpointAllowed($value, $allowed)) {
            throw new LocalizedException(__(
                'The Citecue API Base URL must be an https:// URL whose host is one of the Allowed API Hosts (%1) '
                . 'and is not a private or loopback address. Add the host under "Allowed API Hosts" and save first.',
                implode(', ', $allowed)
            ));
        }

        $this->setValue(rtrim($value, '/'));
        return parent::beforeSave();
    }
}
