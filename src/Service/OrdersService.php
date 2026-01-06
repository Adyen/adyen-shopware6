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

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class OrdersService
{
    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var ClientService
     */
    private ClientService $clientService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ConfigurationService $configurationService
     * @param ClientService $clientService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConfigurationService $configurationService,
        ClientService $clientService,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->logger = $logger;
    }

    /**
     * @param SalesChannelContext $context
     * @param $uuid
     * @param $orderAmount
     * @param $currency
     *
     * @return array
     */
    public function createOrder(SalesChannelContext $context, $uuid, $orderAmount, $currency): array
    {
        $responseData = [];

        try {
            $requestData = $this->buildOrdersRequestData($context, $uuid, $orderAmount, $currency);
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($context->getSalesChannel()->getId())
            );
            $responseData = $checkoutService->orders($requestData);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $responseData;
    }

    /**
     * @param SalesChannelContext $context
     * @param $uuid
     * @param $orderAmount
     * @param $currency
     *
     * @return array
     */
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

        return [
            "reference" => $uuid,
            "amount" => [
                "value" => $orderAmount,
                "currency" => $currency
            ],
            "merchantAccount" => $merchantAccount
        ];
    }
}
