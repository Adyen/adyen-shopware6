<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\Shopware\ScheduledTask\FetchPaymentMethodLogosHandler;

/**
 * Class FetchLogosService
 *
 * A service responsible for providing the handler to fetch payment method logos.
 * This service acts as an intermediary layer for accessing the FetchPaymentMethodLogosHandler.
 *
 * @package Adyen\Shopware\Service
 */
class FetchLogosService
{
    /**
     * @var FetchPaymentMethodLogosHandler
     */
    private FetchPaymentMethodLogosHandler $handler;

    /**
     * FetchLogosService constructor.
     *
     * @param FetchPaymentMethodLogosHandler $handler The handler responsible for fetching payment method logos.
     */
    public function __construct(FetchPaymentMethodLogosHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Get the FetchPaymentMethodLogosHandler.
     *
     * @return FetchPaymentMethodLogosHandler The handler responsible for executing the logo fetching logic.
     */
    public function getHandler(): FetchPaymentMethodLogosHandler
    {
        return $this->handler;
    }
}
