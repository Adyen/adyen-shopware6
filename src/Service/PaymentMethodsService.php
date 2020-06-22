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
use Adyen\Util\Currency;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
     * @var Currency
     */
    private $currency;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    public function __construct(
        EntityRepositoryInterface $salesChannelRepository,
        CheckoutService $checkoutService,
        ConfigurationService $configurationService,
        Currency $currency,
        CartService $cartService
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
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

        $salesChannelCriteria = new Criteria([$context->getSalesChannel()->getId()]);
        $salesChannel = $this->salesChannelRepository->search(
            $salesChannelCriteria->addAssociation('language.locale'),
            Context::createDefaultContext())->first();
        $shopperLocale = $salesChannel->getLanguage()->getLocale()->getCode();


        $currency = $context->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        if ($context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso()) {
            $countryCode = $context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso();
        } else {
            $countryCode = $context->getCustomer()->getActiveShippingAddress()->getCountry()->getIso();
        }
        $shopperReference = $context->getCustomer()->getId();

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
