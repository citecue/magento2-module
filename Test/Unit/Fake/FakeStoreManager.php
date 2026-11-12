<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Fake;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Store manager that always resolves store id 1.
 */
class FakeStoreManager implements StoreManagerInterface
{
    /**
     * @param int|string|null $storeId
     * @return object
     */
    public function getStore($storeId = null)
    {
        return new class {
            /**
             * @return int
             */
            public function getId()
            {
                return 1;
            }
        };
    }
}
