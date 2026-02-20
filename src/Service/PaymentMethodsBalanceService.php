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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

class PaymentMethodsBalanceService
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
     * @param array $paymentMethod
     * @param array $amount
     *
     * @return array
     */
    public function getPaymentMethodsBalance(
        SalesChannelContext $context,
        array $paymentMethod,
        array $amount
    ): array {
        $responseData = [];

        try {
            $requestData = $this->buildPaymentMethodsBalanceRequestData($context, $paymentMethod, $amount);
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($context->getSalesChannel()->getId())
            );

            $this->clientService->logRequest(
                $requestData,
                Client::API_CHECKOUT_VERSION,
                '/paymentMethods/balance',
                $context->getSalesChannelId()
            );

            $responseData = $checkoutService->paymentMethodsBalance($requestData);

            $this->clientService->logResponse(
                $responseData,
                $context->getSalesChannelId()
            );
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $responseData;
    }

    /**
     * @param SalesChannelContext $context
     * @param array $paymentMethod
     * @param array $amount
     *
     * @return array
     */
    private function buildPaymentMethodsBalanceRequestData(
        SalesChannelContext $context,
        array $paymentMethod,
        array $amount
    ): array {
        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        if (!$merchantAccount) {
            $this->logger->error('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
            return [];
        }

        return [
            'paymentMethod' => $paymentMethod,
            'amount' => $amount,
            'merchantAccount' => $merchantAccount
        ];
    }
}
