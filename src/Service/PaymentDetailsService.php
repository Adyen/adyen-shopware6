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
use Adyen\Model\Checkout\PaymentDetailsRequest;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandlerResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class PaymentDetailsService
{
    /**
     * @var ClientService
     */
    private ClientService $clientService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PaymentResponseHandler
     */
    private PaymentResponseHandler $paymentResponseHandler;

    /**
     * PaymentDetailsService constructor.
     *
     * @param LoggerInterface $logger
     * @param ClientService $clientService
     * @param PaymentResponseHandler $paymentResponseHandler
     */
    public function __construct(
        LoggerInterface $logger,
        ClientService $clientService,
        PaymentResponseHandler $paymentResponseHandler
    ) {
        $this->logger = $logger;
        $this->clientService = $clientService;
        $this->paymentResponseHandler = $paymentResponseHandler;
    }

    /**
     * @param PaymentDetailsRequest $requestData
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return PaymentResponseHandlerResult
     *
     * @throws PaymentFailedException
     */
    public function getPaymentDetails(
        PaymentDetailsRequest $requestData,
        OrderTransactionEntity $orderTransaction
    ): PaymentResponseHandlerResult {

        try {
            $paymentsApi = new PaymentsApi(
                $this->clientService->getClient($orderTransaction->getOrder()->getSalesChannelId())
            );

            $paymentDetailsResponse = $paymentsApi->paymentsDetails($requestData);

            return $this->paymentResponseHandler->handlePaymentResponse($paymentDetailsResponse, $orderTransaction);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
