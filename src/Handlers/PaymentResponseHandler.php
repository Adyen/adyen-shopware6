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

use Adyen\Model\Checkout\PaymentDetailsResponse;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Adyen\Shopware\Service\PaymentResponseService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
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
    const ORIGINAL_PSP_REFERENCE = 'originalPspReference';
    const ADDITIONAL_DATA = 'additionalData';
    const ACTION = 'action';
    const DONATION_TOKEN = 'donationToken';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PaymentResponseService
     */
    private PaymentResponseService $paymentResponseService;

    /**
     * @var OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler $transactionStateHandler;

    private $orderTransactionRepository;

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var CaptureService
     */
    private CaptureService $captureService;

    /**
     * @param LoggerInterface $logger
     * @param PaymentResponseService $paymentResponseService
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param $orderTransactionRepository
     * @param CaptureService $captureService
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        LoggerInterface $logger,
        PaymentResponseService $paymentResponseService,
        OrderTransactionStateHandler $transactionStateHandler,
        $orderTransactionRepository,
        CaptureService $captureService,
        ConfigurationService $configurationService
    ) {
        $this->logger = $logger;
        $this->paymentResponseService = $paymentResponseService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->captureService = $captureService;
        $this->configurationService = $configurationService;
    }

    /**
     * @param PaymentResponse|PaymentDetailsResponse $response
     * @param OrderTransactionEntity $orderTransaction
     * @param bool $upsertResponse
     *
     * @return PaymentResponseHandlerResult
     */
    public function handlePaymentResponse(
        $response,
        OrderTransactionEntity $orderTransaction,
        bool $upsertResponse = true
    ): PaymentResponseHandlerResult {
        // TODO Add argument type declaration for response and remove the following block on V4.
        if (!($response instanceof PaymentResponse) && !($response instanceof PaymentDetailsResponse)) {
            throw new \InvalidArgumentException('Invalid $paymentDetailsResponse type.');
        }

        // Retrieve result code from response array
        $resultCode = $response->getResultCode();

        $paymentResponseHandlerResult = new PaymentResponseHandlerResult();
        $paymentResponseHandlerResult->setRefusalReason($response->getRefusalReason());
        $paymentResponseHandlerResult->setRefusalReasonCode($response->getRefusalReasonCode());
        $paymentResponseHandlerResult->setResultCode($resultCode);

        if (($response instanceof PaymentResponse) && !empty($response->getAction())) {
            $paymentResponseHandlerResult->setAction($response->getAction()->toArray());
        }

        $paymentResponseHandlerResult->setPspReference($response->getPspReference());
        $paymentResponseHandlerResult->setAdditionalData($response->getAdditionalData());

        /*
         * If this payment is a part of an Adyen order,
         * payment response contains `order` object.
         */
        $isGiftcardOrderResponse = false;

        $method = $response->getPaymentMethod();

        if (!empty($method) &&
            !empty($method->getType()) &&
            $method->getType() === 'giftcard') {
            $isGiftcardOrderResponse = true;
        }

        $paymentResponseHandlerResult->setIsGiftcardOrder($isGiftcardOrderResponse);

        $donationToken = $response->getDonationToken();

        // Set Donation Token if response contains it, except for giftcards
        if (!empty($donationToken) && !$isGiftcardOrderResponse) {
            $paymentResponseHandlerResult->setDonationToken($donationToken);
        }

        // Store response for cart until the payment is finalised
        $this->paymentResponseService->insertPaymentResponse(
            $response,
            $orderTransaction,
            $upsertResponse
        );

        // Based on the result code start different payment flows
        switch ($resultCode) {
            case self::REFUSED:
                // Log Refused, no further steps needed
                $this->logger->error(
                    "The payment was refused, order transaction merchant reference: " .
                    $response->getMerchantReference()
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

        return $paymentResponseHandlerResult;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @param PaymentResponseHandlerResult[] $paymentResponseHandlerResults
     *
     * @throws PaymentCancelledException
     * @throws PaymentFailedException
     */
    public function handleShopwareApis(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        array $paymentResponseHandlerResults
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();
        $stateTechnicalName = $transaction->getOrderTransaction()->getStateMachineState()->getTechnicalName();
        $requiresManualCapture = $this->captureService
            ->isManualCapture($transaction->getOrderTransaction()->getPaymentMethod()->getHandlerIdentifier());

        // Get already stored transaction custom fields
        $storedTransactionCustomFields = $transaction->getOrderTransaction()->getCustomFields() ?: [];

        // Store action, additionalData and originalPspReference in the transaction
        $transactionCustomFields = [];

        $resultCode = self::AUTHORISED;

        foreach ($paymentResponseHandlerResults as $result) {
            if (self::AUTHORISED !== $result->getResultCode()) {
                $resultCode = $result->getResultCode();
            }
            if ($result->getDonationToken()) {
                $donationToken = $result->getDonationToken();
            }
            // Only store psp reference for the transaction if this is the first/original pspreference and not giftcard
            $pspReference = $result->getPspReference();
            if (empty($storedTransactionCustomFields[self::ORIGINAL_PSP_REFERENCE])
                && !empty($pspReference)
                && (!is_callable([$result, 'isGiftcardOrder']) || !$result->isGiftcardOrder())) {
                $transactionCustomFields[self::ORIGINAL_PSP_REFERENCE] = $pspReference;
            }

            if (is_callable([$result, 'getAction']) &&
                $result->getAction() &&
                empty($storedTransactionCustomFields[self::ACTION])) {
                $transactionCustomFields[self::ACTION] = $result->getAction();
            }

            // Only store additional data for the transaction if this is the first additional data
            $additionalData = $result->getAdditionalData();
            if (empty($storedTransactionCustomFields[self::ADDITIONAL_DATA])
                && !empty($additionalData)
                && (!is_callable([$result, 'isGiftcardOrder']) || !$result->isGiftcardOrder())) {
                $transactionCustomFields[self::ADDITIONAL_DATA] = $additionalData;
            }
        }

        // Check if result is already handled
        if ($this->isTransactionHandled($stateTechnicalName, $resultCode, $requiresManualCapture)) {
            return;
        }

        if (isset($donationToken) &&
            $this->configurationService->isAdyenGivingEnabled($salesChannelContext->getSalesChannelId())) {
            $transactionCustomFields[self::DONATION_TOKEN] = $donationToken;
        }

        $transactionCustomFields['resultCode'] = $resultCode;

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
                } else {
                    $this->transactionStateHandler->process($orderTransactionId, $context);
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
        $pspReference = $paymentResponseHandlerResult->getPspReference();

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
                    "action" => $action,
                    "pspReference" => $pspReference,
                ];
            case self::RECEIVED:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "additionalData" => $additionalData
                ];
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
     *
     * @return bool
     * @throws PaymentCancelledException
     */
    private function isTransactionHandled(
        $transactionStateTechnicalName,
        $resultCode,
        $requiresManualCapture = false
    ) {
        if ($transactionStateTechnicalName === OrderTransactionStates::STATE_OPEN) {
            return false;
        }

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
                    throw new PaymentCancelledException();
                }
                break;
            default:
        }

        return false;
    }
}
