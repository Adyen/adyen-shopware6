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

use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Service\CaptureService;
use Psr\Log\LoggerInterface;
use Adyen\Shopware\Service\PaymentResponseService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentResponseHandler
{
    const AUTHORISED = 'Authorised';
    const REFUSED = 'Refused';
    const REDIRECT_SHOPPER = 'RedirectShopper';
    const IDENTIFY_SHOPPER = 'IdentifyShopper';
    const CHALLENGE_SHOPPER = 'ChallengeShopper';
    const RECEIVED = 'Received';
    const PENDING = 'Pending';
    const PRESENT_TO_SHOPPER = 'PresentToShopper';
    const ERROR = 'Error';
    const CANCELLED = 'Cancelled';

    const PSP_REFERENCE = 'pspReference';
    const ORIGINAL_PSP_REFERENCE = 'originalPspReference';
    const ADDITIONAL_DATA = 'additionalData';
    const ACTION = 'action';


    // Merchant reference parameter in return GET parameters list
    const ADYEN_MERCHANT_REFERENCE = 'adyenMerchantReference';

    // Merchant reference key in API response
    const MERCHANT_REFERENCE = 'merchantReference';

    const PAYMENT_REFERENCE = 'paymentReference';
    const ORDER_TRANSACTION_ID = 'orderTransactionId';
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

    /**
     * @var CaptureService
     */
    private $captureService;

    public function __construct(
        LoggerInterface $logger,
        PaymentResponseService $paymentResponseService,
        OrderTransactionStateHandler $transactionStateHandler,
        PaymentResponseHandlerResult $paymentResponseHandlerResult,
        EntityRepositoryInterface $orderTransactionRepository,
        CaptureService $captureService
    ) {
        $this->logger = $logger;
        $this->paymentResponseService = $paymentResponseService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->captureService = $captureService;
    }

    /**
     * @param array $response
     * @param string|null $orderTransactionId
     * @param string|null $paymentReference
     * @return PaymentResponseHandlerResult
     * @throws ValidationException
     */
    public function handlePaymentResponse(
        array $response,
        string $orderTransactionId = null,
        string $paymentReference = null
    ): PaymentResponseHandlerResult {
        if (is_null($orderTransactionId) && is_null($paymentReference)) {
            throw new ValidationException('Error: either orderTransactionId or paymentReference is required');
        }
        // Retrieve result code from response array
        $resultCode = $response['resultCode'];
        if (array_key_exists('refusalReason', $response)) {
            $this->paymentResponseHandlerResult->setRefusalReason($response['refusalReason']);
        }

        if (array_key_exists('refusalReasonCode', $response)) {
            $this->paymentResponseHandlerResult->setRefusalReasonCode($response['refusalReasonCode']);
        }

        $this->paymentResponseHandlerResult->setResultCode($resultCode);

        // Retrieve PSP reference from response array if available
        if (!empty($response[self::PSP_REFERENCE])) {
            $this->paymentResponseHandlerResult->setPspReference($response[self::PSP_REFERENCE]);
        }

        // Set action in result object if available
        if (!empty($response[self::ACTION])) {
            $this->paymentResponseHandlerResult->setAction($response[self::ACTION]);
        }

        // Set additionalData in result object if available
        if (!empty($response[self::ADDITIONAL_DATA])) {
            $this->paymentResponseHandlerResult->setAdditionalData($response[self::ADDITIONAL_DATA]);
        }

        // Store response for cart until the payment is finalised
        if ($orderTransactionId) {
            $this->paymentResponseService->insertPaymentResponse(
                $response,
                $orderTransactionId,
                self::ORDER_TRANSACTION_ID
            );
        } else {
            $this->paymentResponseService->insertPaymentResponse(
                $response,
                $paymentReference,
                self::PAYMENT_REFERENCE
            );
        }

        // Based on the result code start different payment flows
        switch ($resultCode) {
            case self::REFUSED:
                // Log Refused, no further steps needed
                $this->logger->error(
                    "The payment was refused, order transaction merchant reference: " .
                    $response[self::MERCHANT_REFERENCE]
                );

                break;
            case self::CANCELLED:
            case self::AUTHORISED:
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::RECEIVED:
            case self::PRESENT_TO_SHOPPER:
            case self::PENDING:
                // Do nothing here
                break;
            case self::ERROR:
                // Log error
                $this->logger->error(
                    'There was an error with the payment method. ' .
                    ' Result code "Error" in response: ' . print_r($response, true)
                );

                break;
            default:
                // Log unsupported resultCode
                $this->logger->error(
                    "There was an error with the payment method. id:  " .
                    ' Unsupported result code in response: ' . print_r($response, true)
                );
        }

        return $this->paymentResponseHandlerResult;
    }

    /**
     * Update Order Transaction state based on payment response.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @param PaymentResponseHandlerResult $paymentResponseHandlerResult
     * @throws PaymentCancelledException
     * @throws PaymentFailedException
     */
    public function handleShopwareApis(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        PaymentResponseHandlerResult $paymentResponseHandlerResult // todo we don't need this argument, use $this->pa..
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();
        $stateTechnicalName = $transaction->getOrderTransaction()->getStateMachineState()->getTechnicalName();
        $resultCode = $paymentResponseHandlerResult->getResultCode();
        $requiresManualCapture = $this->captureService
            ->requiresManualCapture($transaction->getOrderTransaction()->getPaymentMethod()->getHandlerIdentifier());

        // Check if result is already handled
        if ($this->isTransactionHandled($stateTechnicalName, $resultCode, $requiresManualCapture)) {
            return;
        }

        // Get already stored transaction custom fields
        $storedTransactionCustomFields = $transaction->getOrderTransaction()->getCustomFields() ?: [];

        // Store action, additionalData and originalPspReference in the transaction
        $transactionCustomFields = [];

        // Only store psp reference for the transaction if this is the first/original pspreference
        $pspReference = $this->paymentResponseHandlerResult->getPspReference();
        if (empty($storedTransactionCustomFields[self::ORIGINAL_PSP_REFERENCE]) && !empty($pspReference)) {
            $transactionCustomFields[self::ORIGINAL_PSP_REFERENCE] = $pspReference;
        }

        // Only store action for the transaction if this is the first action
        $action = $this->paymentResponseHandlerResult->getAction();
        if (empty($storedTransactionCustomFields[self::ACTION]) && !empty($action)) {
            $transactionCustomFields[self::ACTION] = $action;
        }

        // Only store additional data for the transaction if this is the first additional data
        $additionalData = $this->paymentResponseHandlerResult->getAdditionalData();
        if (empty($storedTransactionCustomFields[self::ADDITIONAL_DATA]) && !empty($additionalData)) {
            $transactionCustomFields[self::ADDITIONAL_DATA] = $additionalData;
        }

        // read custom fields before writing to it so we don't mess with other plugins
        $customFields = array_merge(
            $storedTransactionCustomFields,
            $transactionCustomFields
        );

        $transaction->getOrderTransaction()->setCustomFields($customFields);
        $context->scope(
            Context::SYSTEM_SCOPE,
            function (Context $context) use ($orderTransactionId, $customFields) {
                $this->orderTransactionRepository->update([
                    [
                        'id' => $orderTransactionId,
                        'customFields' => $customFields,
                    ]
                ], $context);
            }
        );

        switch ($resultCode) {
            case self::AUTHORISED:
                // Set transaction to process authorised if manual capture is not enabled.
                // Transactions will be set as paid via webhook notification
                if (!$requiresManualCapture) {
                    $this->transactionStateHandler->authorize($orderTransactionId, $context);
                }
                break;
            case self::REFUSED:
                // Fail the order
                $message = 'The payment was refused';
                $this->logger->info($message, ['orderId' => $transaction->getOrder()->getId()]);
                throw new PaymentFailedException($message);
                break;
            case self::CANCELLED:
                // Cancel the order
                $message = 'The payment was cancelled';
                $this->logger->info($message, ['orderId' => $transaction->getOrder()->getId()]);
                throw new PaymentCancelledException($message);
                break;
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::RECEIVED:
            case self::PRESENT_TO_SHOPPER:
            case self::PENDING:
                //The payment is in progress, transition order to do_pay if it's not already there
                if ($stateTechnicalName !== 'in_progress') {
                    // Return to the frontend without throwing an exception
                    $this->transactionStateHandler->process($orderTransactionId, $context);
                }
                break;
            case self::ERROR:
            default:
                // Cancel the order
                $message = 'The payment had an error or an unhandled result code';
                $this->logger->error($message, [
                    'orderId' => $transaction->getOrder()->getId(),
                    'resultCode' => $resultCode,
                ]);
                throw new PaymentFailedException($message);
        }
    }

    public function handleAdyenApis(
        PaymentResponseHandlerResult $paymentResponseHandlerResult
    ): array {
        $resultCode = $paymentResponseHandlerResult->getResultCode();
        $refusalReason = $paymentResponseHandlerResult->getRefusalReason();
        $refusalReasonCode = $paymentResponseHandlerResult->getRefusalReasonCode();
        $action = $paymentResponseHandlerResult->getAction();
        $additionalData = $paymentResponseHandlerResult->getAdditionalData();

        switch ($resultCode) {
            case self::AUTHORISED:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                ];
            case self::REFUSED:
            case self::ERROR:
            case self::CANCELLED:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "refusalReason" => $refusalReason,
                    "refusalReasonCode" => $refusalReasonCode
                ];
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::PRESENT_TO_SHOPPER:
            case self::PENDING:
                return [
                    "isFinal" => false,
                    "resultCode" => $resultCode,
                    "action" => $action
                ];
                break;
            case self::RECEIVED:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "additionalData" => $additionalData
                ];
                break;
            default:
                return [
                    "isFinal" => true,
                    "resultCode" => self::ERROR,
                ];
        }
    }

    /**
     * Validates if the state is already changed where the resultCode would switch it
     * Example: Authorised -> paid, Refused -> failed
     *
     * @param string $transactionStateTechnicalName
     * @param string $resultCode
     * @param bool $requiresManualCapture
     * @return bool
     */
    private function isTransactionHandled(
        $transactionStateTechnicalName,
        $resultCode,
        $requiresManualCapture = false
    ) {
        // TODO check all the states and adyen resultCodes not just the straightforward ones
        switch ($resultCode) {
            case self::AUTHORISED:
                $state = $requiresManualCapture
                    ? OrderTransactionStates::STATE_OPEN
                    : OrderTransactionStates::STATE_AUTHORIZED;

                if ($transactionStateTechnicalName === $state) {
                    return true;
                }
                break;
            case self::REFUSED:
            case self::ERROR:
                if ($transactionStateTechnicalName === OrderTransactionStates::STATE_FAILED) {
                    return true;
                }
                break;
            case self::CANCELLED:
                if ($transactionStateTechnicalName === OrderTransactionStates::STATE_CANCELLED) {
                    return true;
                }
                break;
            default:
        }

        return false;
    }
}
