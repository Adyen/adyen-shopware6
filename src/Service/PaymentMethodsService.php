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
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

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

    /**
     * @var CartService
     */
    private $cartService;

    public function __construct(
        CheckoutService $checkoutService,
        ConfigurationService $configurationService,
        CurrencyService $currency,
        CartService $cartService
    ) {
        $this->checkoutService = $checkoutService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
        $this->cartService = $cartService;
    }

    public function getPaymentMethods(SalesChannelContext $context): array
    {
        try {
            return $this->checkoutService->paymentMethods($this->buildPaymentMethodsRequestData($context));
        } catch (AdyenException $e) {
            //TODO: log this error message
            die($e->getMessage());
        }
    }

    private function buildPaymentMethodsRequestData(SalesChannelContext $context)
    {

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $merchantAccount = $this->configurationService->getMerchantAccount();

        if (!$merchantAccount) {
            //TODO log error
            return array();
        }

        $currency = $context->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        $countryCode = $context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso();
        $shopperReference = $context->getCustomer()->getId();
        $shopperLocale = $context->getCustomer()->getLanguage()->getLocale()->getCode();

        $requestData = array(
            "channel" => "Web",
            "merchantAccount" => $merchantAccount,
            "countryCode" => $countryCode,
            "amount" => array(
                "currency" => $currency,
                "value" => $amount
            ),
            "shopperReference" => $shopperReference,
            "shopperLocale" => $shopperLocale
        );

        return $requestData;
    }

}
