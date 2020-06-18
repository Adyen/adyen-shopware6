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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Shopware\Service\ConfigurationService;
use Petstore30\Order;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

class PaymentMethodsService
{
    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var CurrencyService
     */
    private $currency;

    public function __construct(CheckoutService $checkoutService, ConfigurationService $configurationService, CurrencyService $currency)
    {
        $this->checkoutService = $checkoutService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
    }

    public function getPaymentMethods(OrderEntity $order): array
    {
        try {
            return $this->checkoutService->paymentMethods([$this->buildPaymentMethodsRequestData($order)]);
        } catch (AdyenException $e) {
            //TODO: log this error message
            die($e->getMessage());
        }
    }

    private function buildPaymentMethodsRequestData(OrderEntity $orderEntity)
    {
        $merchantAccount = $this->configurationService->getMerchantAccount();

        if (!$merchantAccount) {
            //TODO log error
            return array();
        }

        $amount = $orderEntity->getAmountTotal();
        $currency = $this->currency->getOrderCurrency($orderEntity, Context::createDefaultContext())->getIsoCode();
        $countryCode = '';//$orderEntity->getAddresses()->first()->getCountry()->getIso(); //TODO is this the shipping or billing address?
        $shopperReference = $orderEntity->getOrderCustomer()->getId();
        $shopperLocale = '';//Shopware()->Shop()->getLocale()->getLocale();

        $requestData = array(
            "channel" => "Web",
            "merchantAccount" => $merchantAccount,
            "countryCode" => $countryCode,
            "amount" => array(
                "currency" => $currency,
                "value" => $this->currency->sanitize($amount, $currency)
            ),
            "shopperReference" => $shopperReference,
            "shopperLocale" => $shopperLocale
        );

        return $requestData;
    }

}
