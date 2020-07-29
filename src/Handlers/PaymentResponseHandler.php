<?php

declare(strict_types=1);
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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Handlers;

use Psr\Log\LoggerInterface;
use Adyen\Shopware\Service\PaymentResponseService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class PaymentResponseHandler
{

    const AUTHORISED = 'Authorised';
    const REFUSED = 'Refused';
    const REDIRECT_SHOPPER = 'RedirectShopper';
    const IDENTIFY_SHOPPER = 'IdentifyShopper';
    const CHALLENGE_SHOPPER = 'ChallengeShopper';
    const RECEIVED = 'Received';
    const PRESENT_TO_SHOPPER = 'PresentToShopper';
    const ERROR = 'Error';


    // Merchant reference parameter in return GET parameters list
    const ADYEN_MERCHANT_REFERENCE = 'adyenMerchantReference';

    // Merchant reference key in API response
    const MERCHANT_REFERENCE = 'merchantReference';
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;

    /**
     * @var PaymentResponseHandlerResult
     */
    private $paymentResponseHandlerResult;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    public function __construct(
        LoggerInterface $logger,
        PaymentResponseService $paymentResponseService,
        OrderTransactionStateHandler $transactionStateHandler,
        PaymentResponseHandlerResult $paymentResponseHandlerResult,
        EntityRepositoryInterface $orderTransactionRepository
    ) {
        $this->logger = $logger;
        $this->paymentResponseService = $paymentResponseService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     * @return
     */
    public function handlePaymentResponse(
        array $response,
        string $orderNumber,
        SalesChannelContext $salesChannelContext
    ): PaymentResponseHandlerResult {
        // Retrieve result code from response array
        $resultCode = $response['resultCode'];

        $this->paymentResponseHandlerResult->setResultCode($resultCode);

        // Retrieve PSP reference from response array if available
        if (!empty($response['pspReference'])) {
            $this->paymentResponseHandlerResult->setPspReference($response['pspReference']);
        }

        // Set action in result object if available
        if (!empty($response['action'])) {
            $this->paymentResponseHandlerResult->setAction($response['action']);
        }

        // Set additionalData in result object if available
        if (!empty($response['additionalData'])) {
            $this->paymentResponseHandlerResult->setAdditionalData($response['additionalData']);
        }

        // Based on the result code start different payment flows
        switch ($resultCode) {
            case self::AUTHORISED:
                // Tag order as payed

                // Store psp reference for the payment $pspReference

                break;
            case self::REFUSED:
                // Log Refused
                $this->logger->error(
                    "The payment was refused, order transaction merchant reference: " .
                    $response[self::MERCHANT_REFERENCE]
                );
                break;
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
                // Store response for cart temporarily until the payment is done
                $this->paymentResponseService->insertPaymentResponse(
                    $response,
                    $orderNumber,
                    $salesChannelContext->getToken()
                );

                return $this->paymentResponseHandlerResult;
                break;
            case self::RECEIVED:
            case self::PRESENT_TO_SHOPPER:
                // Store payments response for later use
                $this->paymentResponseService->insertPaymentResponse(
                    $response,
                    $orderNumber,
                    $salesChannelContext->getToken()
                );

                // Tag the order as waiting for payment
                // TODO create new order status
                break;
            case self::ERROR:
                // Log error
                $this->logger->error(
                    'There was an error with the payment method. ' .
                    ' Result code "Error" in response: ' . print_r($response, true)
                );

                break;
            default:
                // Unsupported resultCode
                $this->logger->error(
                    "There was an error with the payment method. id:  " .
                    ' Unsupported result code in response: ' . print_r($response, true)
                );

                break;
        }
    }

    public function handleShopwareAPIs(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        PaymentResponseHandlerResult $paymentResponseHandlerResult
    ) {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $resultCode = $paymentResponseHandlerResult->getResultCode();
        $context = $salesChannelContext->getContext();

        // Only store psp reference for the transaction if this is the firs/original pspreference
        $storedTransactionCustomFields = $transaction->getOrderTransaction()->getCustomFields() ?: [];
        $pspReference = $this->paymentResponseHandlerResult->getPspReference();
        if (empty($storedTransactionCustomFields['originalPspReference']) && !empty($pspReference)) {

            // read custom fields before writing to it so we don't mess with other plugins
            $customFields = array_merge(
                $storedTransactionCustomFields,
                ['originalPspReference' => $pspReference]
            );

            $transaction->getOrderTransaction()->setCustomFields($customFields);

            $this->orderTransactionRepository->update(
                ['id' => $orderTransactionId, 'customFields' => $customFields],
                $context
            );
        }

        switch ($resultCode) {
            case self::AUTHORISED:
                // Tag order as paid
                $this->transactionStateHandler->paid($orderTransactionId, $context);
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::RECEIVED:
            case self::PRESENT_TO_SHOPPER:
                // Return to the frontend without throwing an exception
                return new RedirectResponse('redirectUrl');
                break;
            case self::REFUSED:
            case self::ERROR:
            default:
                // Cancel the order
                throw new AsyncPaymentProcessException(
                    $orderTransactionId,
                    'The payment was cancelled, refused or had an error or an unhandled result code'
                );
        }
    }

    public function handleAdyenApis(
        PaymentResponseHandlerResult $paymentResponseHandlerResult
    ): JsonResponse {
        $resultCode = $paymentResponseHandlerResult->getResultCode();

        switch ($resultCode) {
            case self::AUTHORISED:
            case self::REFUSED:
            case self::ERROR:
                return new JsonResponse(
                    [
                        "resultCode" => $this->paymentResponseHandlerResult->getResultCode(),
                    ]
                );
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::PRESENT_TO_SHOPPER:
                return new JsonResponse(
                    [
                        "resultCode" => $this->paymentResponseHandlerResult->getResultCode(),
                        "action" => $this->paymentResponseHandlerResult->getAction()
                    ]
                );
                break;
            case self::RECEIVED:
                return new JsonResponse(
                    [
                        "resultCode" => $this->paymentResponseHandlerResult->getResultCode(),
                        "additionalData" => $this->paymentResponseHandlerResult->getAdditionalData()
                    ]
                );
                break;
            default:
                return new JsonResponse(
                    [
                        "resultCode" => self::ERROR,
                    ]
                );
        }
    }
}
