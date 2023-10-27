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

use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\CreateOrderRequest;
use Adyen\Model\Checkout\CreateOrderResponse;
use Adyen\Service\Checkout\OrdersApi;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class OrdersService
{
    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ConfigurationService $configurationService,
        ClientService $clientService,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->logger = $logger;
    }

    public function createOrder(SalesChannelContext $context, $uuid, $orderAmount, $currency): CreateOrderResponse
    {
        $responseData = new CreateOrderResponse();

        try {
            $requestData = $this->buildOrdersRequestData($context, $uuid, $orderAmount, $currency);

            $orderService = new OrdersApi($this->clientService->getClient($context->getSalesChannel()->getId()));
            $responseData = $orderService->orders($requestData);

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $responseData;
    }

    private function buildOrdersRequestData(
        SalesChannelContext $context,
        $uuid,
        $orderAmount,
        $currency
    ): CreateOrderRequest {

        $request = new CreateOrderRequest();
        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        if (!$merchantAccount) {
            $this->logger->error('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
            return $request;
        }

        $amount = new Amount();
        $amount->setValue($orderAmount);
        $amount->setCurrency($currency);

        $request->setAmount($amount);
        $request->setMerchantAccount($merchantAccount);
        $request->setReference($uuid);

        return $request;
    }
}
