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
    private $clientService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

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
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     */
    public function getPaymentDetails(
        //todo: check if this is okay, not sure if $requestData can be an object or not, param type is not clear
        PaymentDetailsRequest $requestData,
        OrderTransactionEntity $orderTransaction
    ): PaymentResponseHandlerResult {

        try {
//            $checkoutService = new CheckoutService(
//                $this->clientService->getClient($orderTransaction->getOrder()->getSalesChannelId())
//            );
            $paymentsApiObj = new PaymentsApi(
                $this->clientService->getClient($orderTransaction->getOrder()->getSalesChannelId())
            );

            // TODO: Confirm: the paymentDetails returns 'mixed', considering we need the response to be paymentDetailsResponse type for handlePaymentResponse, I am assuming this $response will still work
            $response = $paymentsApiObj->paymentsDetails($requestData);
            return $this->paymentResponseHandler->handlePaymentResponse($response, $orderTransaction);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
