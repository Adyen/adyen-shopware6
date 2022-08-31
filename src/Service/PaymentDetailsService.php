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
use Adyen\Shopware\Exception\PaymentFailedException;
use Psr\Log\LoggerInterface;

class PaymentDetailsService
{
    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * PaymentDetailsService constructor.
     *
     * @param LoggerInterface $logger
     * @param ClientService $clientService
     */
    public function __construct(
        LoggerInterface $logger,
        ClientService $clientService
    ) {
        $this->logger = $logger;
        $this->clientService = $clientService;
    }

    /**
     * @param array $requestData
     * @param string $salesChannelId
     * @return mixed
     * @throws PaymentFailedException
     */
    public function getPaymentDetails(
        array $requestData,
        string $salesChannelId
    ) {

        try {
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($salesChannelId)
            );
            return $checkoutService->paymentsDetails($requestData);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
