<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Test Connection" button (+ result area) in the module's
 * system configuration section.
 */
class TestConnection extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Citecue_Delivery::system/config/test_connection.phtml';

    /**
     * Drop scope label/inheritance checkbox for the button row.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Renders the button template as the element's HTML.
     *
     * @param AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * The admin URL of the Test Connection AJAX endpoint.
     *
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('citecue/system_config/testconnection');
    }
}
