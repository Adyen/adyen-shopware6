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

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

class PaymentMethodsBalanceService
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


    public function getPaymentMethodsBalance(SalesChannelContext $context, string $type, string $number, string $cvc): array
    {
        $responseData = [];

        try {
            $requestData = $this->buildPaymentMethodsBalanceRequestData($context, $type, $number, $cvc);

            $checkoutService = new CheckoutService(
                $this->clientService->getClient($context->getSalesChannel()->getId())
            );
            $responseData = $checkoutService->paymentMethodsBalance($requestData);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $responseData;
    }

    private function buildPaymentMethodsBalanceRequestData(SalesChannelContext $context, string $type, string $number, string $cvc): array
    {
        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        $requestData = array(
            "paymentMethod" => [
                "type" => $type,
                "number" => $number,
                "cvc" => $cvc
            ],
            "merchantAccount" => $merchantAccount
        );

        return $requestData;
    }
}
