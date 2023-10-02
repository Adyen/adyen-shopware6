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
        PaymentDetailsRequest $requestData,
        OrderTransactionEntity $orderTransaction
    ): PaymentResponseHandlerResult {

        try {
//            $checkoutService = new CheckoutService(
//                $this->clientService->getClient($orderTransaction->getOrder()->getSalesChannelId())
//            );
            $paymentsApi = new PaymentsApi(
                $this->clientService->getClient($orderTransaction->getOrder()->getSalesChannelId())
            );

            $paymentDetailsResponse = $paymentsApi->paymentsDetails($requestData);

//            // instantiate a PaymentResponse object from the values of paymentDetailsResponse object
//            $response = new PaymentResponse();
//
//            if(!is_null($paymentDetailsResponse->getAdditionalData())){
//                $response->setAdditionalData($paymentDetailsResponse->getAdditionalData());
//            }
//            if(!is_null($paymentDetailsResponse->getPaymentMethod())){
//                $response->setPaymentMethod($paymentDetailsResponse->getPaymentMethod());
//            }
//            if(!is_null($paymentDetailsResponse->getAmount())){
//                $response->setAmount($paymentDetailsResponse->getAmount());
//            }
//            if(!is_null($paymentDetailsResponse->getPspReference())){
//                $response->setPspReference($paymentDetailsResponse->getPspReference());
//            }
//            if(!is_null($paymentDetailsResponse->getRefusalReason())){
//                $response->setRefusalReason($paymentDetailsResponse->getRefusalReason());
//            }
//            if(!is_null($paymentDetailsResponse->getResultCode())){
//                $response->setResultCode($paymentDetailsResponse->getResultCode());
//            }
//            if(!is_null($paymentDetailsResponse->getDonationToken())) {
//                $response->setDonationToken($paymentDetailsResponse->getDonationToken());
//            }
//            if(!is_null($paymentDetailsResponse->getFraudResult())){
//                $response->setFraudResult($paymentDetailsResponse->getFraudResult());
//            }
//            if(!is_null($paymentDetailsResponse->getMerchantReference())){
//                $response->setMerchantReference($paymentDetailsResponse->getMerchantReference());
//            }
//            if(!is_null($paymentDetailsResponse->getOrder())){
//                $response->setOrder($paymentDetailsResponse->getOrder());
//            }

            return $this->paymentResponseHandler->handlePaymentResponse($paymentDetailsResponse, $orderTransaction);

        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
