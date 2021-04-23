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
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentMethodsService
{
    /**
     * @var ClientService
     */
    private $clientService;

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
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * PaymentMethodsService constructor.
     *
     * @param LoggerInterface $logger
     * @param ClientService $clientService
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param CartService $cartService
     * @param SalesChannelRepository $salesChannelRepository
     * @param OrderRepository $orderRepository
     */
    public function __construct(
        LoggerInterface $logger,
        ClientService $clientService,
        ConfigurationService $configurationService,
        Currency $currency,
        CartService $cartService,
        SalesChannelRepository $salesChannelRepository,
        OrderRepository $orderRepository
    ) {
        $this->clientService = $clientService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
        $this->cartService = $cartService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param SalesChannelContext $context
     * @param string $orderId
     * @return array
     */
    public function getPaymentMethods(SalesChannelContext $context, $orderId = ''): array
    {
        $responseData = [];
        try {
            $requestData = $this->buildPaymentMethodsRequestData($context, $orderId);
            if (!empty($requestData)) {
                $checkoutService = new CheckoutService(
                    $this->clientService->getClient($context->getSalesChannel()->getId())
                );
                $responseData = $checkoutService->paymentMethods($requestData);
            }
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
        }
        return $responseData;
    }

    /**
     * @param SalesChannelContext $context
     * @param string $orderId
     * @return array
     */
    private function buildPaymentMethodsRequestData(SalesChannelContext $context, $orderId = '')
    {
        if (is_null($context->getCustomer())) {
            return [];
        }

        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        if (!$merchantAccount) {
            $this->logger->error('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
            return [];
        }

        // Retrieve data from cart if no order is created yet
        if ($orderId === '') {
            $currency = $context->getCurrency()->getIsoCode();
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $amount = $this->currency->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        } else {
            $order = $this->orderRepository->getOrder($orderId, $context->getContext(), ['currency']);
            $currency = $order->getCurrency()->getIsoCode();
            $amount = $this->currency->sanitize($order->getPrice()->getTotalPrice(), $currency);
        }

        $salesChannelAssocLocale = $this->salesChannelRepository->getSalesChannelAssocLocale($context);
        $shopperLocale = $salesChannelAssocLocale->getLanguage()->getLocale()->getCode();

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
