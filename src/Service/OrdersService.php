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

use Adyen\Client;
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

    public function createOrder(SalesChannelContext $context, $uuid, $orderAmount, $currency): array
    {
        $responseData = [];

        try {
            $requestData = $this->buildOrdersRequestData($context, $uuid, $orderAmount, $currency);
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($context->getSalesChannel()->getId())
            );

            $this->clientService->logRequest(
                $requestData,
                Client::API_CHECKOUT_VERSION,
                '/orders',
                $context->getSalesChannelId()
            );

            $responseData = $checkoutService->orders($requestData);

            $this->clientService->logResponse(
                $responseData,
                $context->getSalesChannelId()
            );
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
    ): array {
        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        if (!$merchantAccount) {
            $this->logger->error('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
            return [];
        }

        $requestData = array(
            "reference" => $uuid,
            "amount" => [
                "value" => $orderAmount,
                "currency" => $currency
            ],
            "merchantAccount" => $merchantAccount
        );

        return $requestData;
    }
}
