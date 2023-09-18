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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Model\Checkout\PaymentMethodsRequest;
use Adyen\Model\Checkout\PaymentMethodsResponse;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Adapter\Cache\CacheValueCompressor;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Cache\CacheInterface;

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
     * @var CacheInterface
     */
    private $cache;

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
     * @param CacheInterface $cache
     * @param SalesChannelRepository $salesChannelRepository
     * @param OrderRepository $orderRepository
     */
    public function __construct(
        LoggerInterface $logger,
        ClientService $clientService,
        ConfigurationService $configurationService,
        Currency $currency,
        CartService $cartService,
        CacheInterface $cache,
        SalesChannelRepository $salesChannelRepository,
        OrderRepository $orderRepository
    ) {
        $this->clientService = $clientService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
        $this->cartService = $cartService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param SalesChannelContext $context
     * @param string $orderId
     * @return array
     */
    public function getPaymentMethods(SalesChannelContext $context, $orderId = ''): PaymentMethodsResponse
    {
        $requestData = $this->buildPaymentMethodsRequestData($context, $orderId);

        $paymentRequestString = json_encode($requestData);
        $cacheKey = 'adyen_payment_methods_' . md5($paymentRequestString);
        $paymentMethodsResponseCache = $this->cache->getItem($cacheKey);

        if ($paymentMethodsResponseCache->isHit() && $paymentMethodsResponseCache->get()) {
            return CacheValueCompressor::uncompress($paymentMethodsResponseCache->get());
        }

        $responseData = [];
        try {
            $paymentsApiService = new PaymentsApi(
                $this->clientService->getClient($context->getSalesChannelId())
            );
//            $checkoutService = new CheckoutService(
//                $this->clientService->getClient($context->getSalesChannelId())
//            );
            $responseData = $paymentsApiService->paymentMethods(new PaymentMethodsRequest($requestData));

            $paymentMethodsResponseCache->set(CacheValueCompressor::compress($responseData));
            $this->cache->save($paymentMethodsResponseCache);
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
        }

        return $responseData;
    }

    /**
     * @param string $address
     * @return array
     */
    public function getSplitStreetAddressHouseNumber(string $address): array
    {
        $streetFirstRegex = '/(?<streetName>[\w\W]+)\s+(?<houseNumber>[\d-]{1,10}((\s)?\w{1,3})?)$/m';
        $numberFirstRegex = '/^(?<houseNumber>[\d-]{1,10}((\s)?\w{1,3})?)\s+(?<streetName>[\w\W]+)/m';

        preg_match($streetFirstRegex, $address, $streetFirstAddress);
        preg_match($numberFirstRegex, $address, $numberFirstAddress);

        if ($streetFirstAddress) {
            return [
                'street' => $streetFirstAddress['streetName'],
                'houseNumber' => $streetFirstAddress['houseNumber']
            ];
        } elseif ($numberFirstAddress) {
            return [
                'street' => $numberFirstAddress['streetName'],
                'houseNumber' => $numberFirstAddress['houseNumber']
            ];
        }

        return [
            'street' => $address,
            'houseNumber' => 'N/A'
        ];
    }

    /**
     * @param SalesChannelContext $context
     * @param string $orderId
     * @return array
     */
    private function buildPaymentMethodsRequestData(SalesChannelContext $context, $orderId = '')
    {
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

        $salesChannelAssocLocale = $this->salesChannelRepository
            ->getSalesChannelAssoc($context, ['language.locale', 'country']);
        $shopperLocale = $salesChannelAssocLocale->getLanguage()->getLocale()->getCode();

        if (!is_null($context->getCustomer())) {
            if ($context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso()) {
                $countryCode = $context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso();
            } else {
                $countryCode = $context->getCustomer()->getActiveShippingAddress()->getCountry()->getIso();
            }
            $shopperReference = $context->getCustomer()->getId();
        } else {
            // Use sales channel default country and generic shopper reference in shopping cart view
            $countryCode = $salesChannelAssocLocale->getCountry()->getIso();
            $shopperReference = 'shopping-cart-user-' . uniqid();
        }

        return [
            "channel" => "Web",
            "merchantAccount" => $merchantAccount,
            "countryCode" => $countryCode,
            "amount" => [
                "currency" => $currency,
                "value" => $amount
            ],
            "shopperReference" => $shopperReference,
            "shopperLocale" => $shopperLocale
        ];
    }
}
