<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2019 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Api;

/**
 * Interface for fetching the Adyen origin key
 *
 * @api
 */
interface AdyenOriginKeyInterface
{
    /**
     * @return string
     */
    public function getOriginKey();
}
