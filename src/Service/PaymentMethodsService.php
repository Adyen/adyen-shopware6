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

    /**
     * @var LoggerService
     */
    private $loggerService;

    /**
     * PaymentMethodsService constructor.
     * @param EntityRepositoryInterface $salesChannelRepository
     * @param CheckoutService $checkoutService
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param CartService $cartService
     * @param LoggerService $loggerService
     */
    public function __construct(
        EntityRepositoryInterface $salesChannelRepository,
        CheckoutService $checkoutService,
        ConfigurationService $configurationService,
        Currency $currency,
        CartService $cartService,
        LoggerService $loggerService
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->checkoutService = $checkoutService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
        $this->cartService = $cartService;
        $this->loggerService = $loggerService;
    }

    /**
     * @param SalesChannelContext $context
     * @return array
     */
    public function getPaymentMethods(SalesChannelContext $context): array
    {
        $responseData = [];
        try {
            $requestData = $this->buildPaymentMethodsRequestData($context);
            if (!empty($requestData)) {
                $this->loggerService->addAdyenAPI(sprintf('/paymentMethods request sent to Adyen: %s',
                    json_encode($requestData)));
                $responseData = $this->checkoutService->paymentMethods($requestData);
                $this->loggerService->addAdyenAPI(sprintf('/paymentMethods response from Adyen: %s',
                    json_encode($responseData)));
            }
        } catch (AdyenException $e) {
            $this->loggerService->addAdyenError($e->getMessage());
        }
        return $responseData;
    }

    /**
     * @param SalesChannelContext $context
     * @return array
     */
    private function buildPaymentMethodsRequestData(SalesChannelContext $context)
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $merchantAccount = $this->configurationService->getMerchantAccount();

        if (!$merchantAccount) {
            $this->loggerService->addAdyenError('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
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
