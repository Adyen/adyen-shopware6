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

use Adyen\Shopware\Service\Repository\OrderRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class OrdersCancelService
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
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ConfigurationService $configurationService,
        ClientService $clientService,
        OrderRepository $orderRepository,
        LoggerInterface $logger

    ) {
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    public function getOrdersCancel(SalesChannelContext $context, $orderId): array
    {
        $responseData = [];

        try {
            $requestData = $this->buildOrdersCancelRequestData($context, $orderId);
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($context->getSalesChannel()->getId())
            );
            $responseData = $checkoutService->ordersCancel($requestData);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $responseData;
    }

    private function buildOrdersCancelRequestData(SalesChannelContext $context, $orderId): array
    {
        $order = $this->orderRepository->getOrder($orderId, $context->getContext(), ['currency']);
        $pspReference = $order->getCustomFields()['pspReference'];
        $orderData = $order->getCustomFields()['orderData'];
        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        if (!$merchantAccount) {
            $this->logger->error('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
            return [];
        }

        $requestData = array(
            "order" => [
                "pspReference" => $pspReference,
                "orderData" => $orderData
            ],
            "merchantAccount" => $merchantAccount
        );

        return $requestData;
    }
}
