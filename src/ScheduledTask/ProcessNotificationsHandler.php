<?php declare(strict_types=1);
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
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\ResultHandler;
use Adyen\Shopware\Models\NotificationProcessingResult;
use Adyen\Shopware\ScheduledTask\Webhook\AuthorisationWebhookHandler;
use Adyen\Shopware\ScheduledTask\Webhook\WebhookHandlerFactory;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaypalPaymentService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Notification;
use Adyen\Webhook\PaymentStates;
use Adyen\Webhook\Processor\ProcessorFactory;
use Adyen\Webhook\EventCodes;
use Adyen\Webhook\Processor\ProcessorInterface;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;

class ProcessNotificationsHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    const WEBHOOK_TRANSACTION_STATES = [
        OrderTransactionStates::STATE_OPEN,
        OrderTransactionStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_AUTHORIZED,
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_PARTIALLY_PAID,
        OrderTransactionStates::STATE_REFUNDED,
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
    ];

    public const MAX_ERROR_COUNT = 3;

    /**
     * @var NotificationService
     */
    private NotificationService $notificationService;

    /**
     * @var OrderRepository
     */
    private OrderRepository $orderRepository;

    private $paymentMethodRepository;

    /**
     * @var array|null
     */
    private ?array $adyenPaymentMethodIds = null;

    /**
     * @var OrderTransactionRepository
     */
    private OrderTransactionRepository $orderTransactionRepository;

    /**
     * @var AdyenPaymentService
     */
    private AdyenPaymentService $adyenPaymentService;

    /**
     * @var CaptureService
     */
    private CaptureService $captureService;

    /**
     * @var WebhookHandlerFactory
     */
    private static WebhookHandlerFactory $webhookHandlerFactory;

    /**
     * @var PaymentResponseService $paymentResponseService
     */
    private PaymentResponseService $paymentResponseService;

    /**
     * @var PaypalPaymentService
     */
    private PaypalPaymentService $paypalPaymentService;

    /**
     * @var OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler $orderTransactionStateHandler;

    /**
     * @var array Map Shopware transaction states to payment states in the webhook module.
     */
    const WEBHOOK_MODULE_STATE_MAPPING = [
        OrderTransactionStates::STATE_OPEN => PaymentStates::STATE_NEW,
        OrderTransactionStates::STATE_AUTHORIZED => PaymentStates::STATE_PENDING,
        OrderTransactionStates::STATE_PAID => PaymentStates::STATE_PAID,
        OrderTransactionStates::STATE_FAILED => PaymentStates::STATE_FAILED,
        OrderTransactionStates::STATE_IN_PROGRESS => PaymentStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_REFUNDED => PaymentStates::STATE_REFUNDED,
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED => PaymentStates::STATE_PARTIALLY_REFUNDED,
    ];

    /**
     * @param EntityRepository $scheduledTaskRepository
     * @param NotificationService $notificationService
     * @param OrderRepository $orderRepository
     * @param $paymentMethodRepository
     * @param OrderTransactionRepository $orderTransactionRepository
     * @param AdyenPaymentService $adyenPaymentService
     * @param CaptureService $captureService
     * @param WebhookHandlerFactory $webhookHandlerFactory
     * @param PaymentResponseService $paymentResponseService
     * @param PaypalPaymentService $paypalPaymentService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        $paymentMethodRepository,
        OrderTransactionRepository $orderTransactionRepository,
        AdyenPaymentService $adyenPaymentService,
        CaptureService $captureService,
        WebhookHandlerFactory $webhookHandlerFactory,
        PaymentResponseService $paymentResponseService,
        PaypalPaymentService $paypalPaymentService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->adyenPaymentService = $adyenPaymentService;
        $this->captureService = $captureService;
        $this->paymentResponseService = $paymentResponseService;
        $this->paypalPaymentService = $paypalPaymentService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        self::$webhookHandlerFactory = $webhookHandlerFactory;
    }

    public static function getHandledMessages(): iterable
    {
        return [ ProcessNotifications::class ];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $notifications = $this->notificationService->getScheduledUnprocessedNotifications();

        foreach ($notifications->getElements() as $notification) {
            try {
                /** @var NotificationEntity $notification */
                $logContext = ['eventCode' => $notification->getEventCode()];

                if (is_null($notification->getMerchantReference())) {
                    $this->markAsDone($notification->getId(), '');
                    continue;
                }

                /*
                 * Before processing any notification, factory should be created first.
                 * It checks the supported EventCode to use related class in the factory.
                 * If the EventCode is not supported, factory will throw an InvalidDataException.
                 */
                $webhookHandler = self::$webhookHandlerFactory::create($notification->getEventCode());

                $this->markAsProcessing($notification->getId(), $notification->getMerchantReference());

                $order = $this->getOrder($notification, $context, $logContext);
                if (is_null($order)) {
                    continue;
                }

                $logContext['orderId'] = $order->getId();
                $logContext['orderNumber'] = $order->getOrderNumber();

                $orderTransaction = $this->getOrderTransaction($order, $notification, $logContext);
                if (is_null($orderTransaction)) {
                    continue;
                }

                // The transaction from the payment metadata can be of another order of this shop with the same order
                // number, e.g. when sales channels have their own number ranges.
                $transactionOrderId = $orderTransaction->getOrder() ? $orderTransaction->getOrder()->getId() : null;
                if (!is_null($transactionOrderId) && $transactionOrderId !== $order->getId()) {
                    $order = $this->orderRepository->getOrder(
                        $transactionOrderId,
                        $context,
                        ['transactions', 'currency']
                    ) ?? $order;
                    $logContext['orderId'] = $order->getId();
                }

                $isRecoverable = $this->isRecoverableFailedTransaction($orderTransaction, $notification);
                if ($isRecoverable && !$this->isLatestOrderTransaction($order, $orderTransaction)) {
                    $errorMessage = 'Skipped: Payment was authorised for a failed transaction that a newer ' .
                        'transaction of the order has replaced. Check whether the payment has to be refunded.';
                    $this->logger->warning($errorMessage, $logContext);
                    $this->logNotificationFailure($notification, $errorMessage);
                    $this->markAsDone($notification->getId(), $notification->getMerchantReference());
                    continue;
                }

                $customFields = $orderTransaction->getCustomFields();
                $customFieldsChanged = false;

                // Needed for capture and refund when the shopper did not return to the shop after the payment.
                // The key is set even without a reference, as the refund only checks that it exists.
                if (empty($customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE])) {
                    $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] =
                        $this->getPaymentPspReference($orderTransaction, $notification);
                    $customFieldsChanged = true;
                }

                // The marker is cleared, so the transaction is reopened only once.
                if ($isRecoverable) {
                    $customFields[ResultHandler::PAYMENT_DETAILS_CALL_FAILED] = null;
                    $customFieldsChanged = true;
                }

                if ($customFieldsChanged) {
                    $orderTransaction->setCustomFields($customFields);
                    $this->orderTransactionRepository->updateCustomFields($orderTransaction);
                }

                $originalTransactionState = $this->getCurrentTransactionState($orderTransaction, $notification);
                if (is_null($originalTransactionState)) {
                    continue;
                }

                $currentTransactionState = $isRecoverable ?
                    PaymentStates::STATE_IN_PROGRESS :
                    $originalTransactionState;

                try {
                    if ($isRecoverable) {
                        $this->reopenFailedTransaction($orderTransaction, $context, $logContext);
                    }

                    $processor = $this->createProcessor($notification, $currentTransactionState);
                    if (is_null($processor)) {
                        continue;
                    }

                    $state = $processor->process();

                    $this->logger->info('Processed ' . $notification->getEventCode() . ' notification.', [
                        'eventCode' => $notification->getEventCode(),
                        'originalState' => $originalTransactionState,
                        'newState' => $state
                    ]);

                    $webhookHandler->handleWebhook(
                        $orderTransaction,
                        $notification,
                        $state,
                        $currentTransactionState,
                        $context
                    );
                } finally {
                    if ($isRecoverable) {
                        $this->restoreUnsettledTransaction($orderTransaction, $context, $logContext);
                    }
                }
            } catch (CaptureException $exception) {
                $this->logger->warning($exception->getMessage(), ['code' => $exception->getCode()]);
                $scheduledProcessingTime = $this->captureService->getRescheduleNotificationTime();
                if (CaptureService::REASON_DELIVERY_STATE_MISMATCH === $exception->reason ||
                    CaptureService::REASON_WAITING_AUTH_WEBHOOK === $exception->reason) {
                    $this->rescheduleNotification(
                        $notification->getId(),
                        $notification->getMerchantReference(),
                        $scheduledProcessingTime
                    );
                } else {
                    $this->logNotificationFailure($notification, $exception->getMessage());
                    if ($notification->getErrorCount() < self::MAX_ERROR_COUNT) {
                        $this->rescheduleNotification($notification->getId(), $notification->getMerchantReference());
                    } else {
                        $this->markAsDone($notification->getId(), $notification->getMerchantReference());
                    }
                }

                continue;
            } catch (InvalidDataException $exception) {
                /*
                 * This notification can't be recognised and handled by the plugin.
                 * It will be marked as done.
                 */
                $this->logger->info($exception->getMessage(), $logContext);
            } catch (\Exception $exception) {
                $logContext['errorMessage'] = $exception->getMessage();
                $logContext['stackTrace'] = $exception->getTraceAsString();
                $this->logger->error('Notification processing failed.', $logContext);
                $this->logNotificationFailure($notification, $exception->getMessage());

                if ($notification->getErrorCount() < self::MAX_ERROR_COUNT) {
                    $this->rescheduleNotification($notification->getId(), $notification->getMerchantReference());
                } else {
                    $this->markAsDone($notification->getId(), $notification->getMerchantReference());
                }

                continue;
            }

            $this->markAsDone($notification->getId(), $notification->getMerchantReference());
        }

        $this->logger->info('Processed ' . $notifications->count() . ' notifications.');
    }

    /**
     * @param NotificationEntity $notification
     * @param string $currentTransactionState
     * @return ProcessorInterface|null
     */
    private function createProcessor(
        NotificationEntity $notification,
        string $currentTransactionState
    ): ?ProcessorInterface {
        try {
            // The additional data is needed for CANCEL_OR_REFUND, it tells whether a cancellation or a refund was done
            $additionalData = json_decode($notification->getAdditionalData() ?? '', true);
            $notificationItem = Notification::createItem([
                'eventCode' => $notification->getEventCode(),
                'success' => $notification->isSuccess(),
                'additionalData' => is_array($additionalData) ? $additionalData : []
            ]);

            $isManual = (bool) $this->captureService->isManualCaptureActive();
            $isOnShipment = (bool) $this->captureService->isCaptureOnShipmentEnabled();

            $isAutoCapture = !($isManual || $isOnShipment);

            return ProcessorFactory::create(
                $notificationItem,
                $currentTransactionState,
                $isAutoCapture
            );
        } catch (InvalidDataException $exception) {
            $logContext['notification'] = $notification->getVars();
            $errorMessage = 'Skipped: Unable to process notification. Invalid notification data';
            $this->logger->error($errorMessage, $logContext);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());

            return null;
        }
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @return string|null
     */
    private function getCurrentTransactionState(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): ?string {
        $currentTransactionState = self::WEBHOOK_MODULE_STATE_MAPPING[
            $orderTransaction->getStateMachineState()->getTechnicalName()
            ] ?? '';

        if (empty($currentTransactionState)) {
            $logContext['paymentState'] = $orderTransaction->getStateMachineState()->getTechnicalName();
            $errorMessage = 'Skipped: Current order transaction payment state is not supported.';
            $this->logger->error($errorMessage, $logContext);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());

            return null;
        } else {
            return $currentTransactionState;
        }
    }

    /**
     * @param OrderEntity $order
     * @param NotificationEntity $notification
     * @param array $logContext
     * @return OrderTransactionEntity|null
     */
    private function getOrderTransaction(
        OrderEntity $order,
        NotificationEntity $notification,
        array $logContext
    ): ?OrderTransactionEntity {
        $orderTransaction = null;

        // Several shops can use the same merchant account and have orders with the same order number, and Adyen
        // sends the webhooks of all of them to every shop. The webhooks of a payment carry its metadata.
        $metadataTransactionId = $this->getMetadataOrderTransactionId($notification);
        if (!is_null($metadataTransactionId)) {
            return $this->getOrderTransactionFromMetadata($order, $notification, $metadataTransactionId, $logContext);
        }

        /* Fetch the related order_transaction entity with the given pspreference.
         * There might be several order transactions if there have been multiple payment attempts. */
        $adyenPaymentResponse = $this->paymentResponseService->getWithPspreference($notification->getPspreference());
        if (isset($adyenPaymentResponse)) {
            $orderTransaction = $this->orderTransactionRepository->getWithId(
                $adyenPaymentResponse->getOrderTransactionId()
            );
        }

        if (is_null($orderTransaction)) {
            $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
                $order->getId(),
                self::WEBHOOK_TRANSACTION_STATES
            );

            if (!is_null($orderTransaction) &&
                $this->belongsToAnotherPaymentAttempt($orderTransaction, $notification)) {
                $orderTransaction = null;
            }
        }

        if (is_null($orderTransaction) && $this->canSettleFailedTransaction($notification)) {
            $orderTransaction = $this->orderTransactionRepository
                ->getAdyenOrderTransactionsByStates($order->getId(), [OrderTransactionStates::STATE_FAILED])
                ->filter(fn (OrderTransactionEntity $transaction) => $this->hasPaymentDetailsCallFailedMarker(
                    $transaction
                ))
                ->first();
        }

        // Skip if orderTransaction not found (non-Adyen)
        if (is_null($orderTransaction)) {
            $errorMessage = sprintf(
                'Skipped: Unable to identify Adyen orderTransaction linked to order %s',
                $order->getOrderNumber()
            );
            $this->logger->error($errorMessage, $logContext);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());
            return null;
        }

        if ($this->isModificationOfAnotherPayment($orderTransaction, $notification)) {
            $this->skipNotificationOfAnotherPayment($order, $notification, $logContext);

            return null;
        }

        return $orderTransaction;
    }

    /**
     * Loads the transaction whose ID was sent in the payment request metadata. The notification is skipped if
     * this shop has no such transaction or its order has another order number, e.g. it is the payment of another
     * shop with the same order number, or if the transaction cannot take the notification, in the same way as
     * without the metadata.
     *
     * @param OrderEntity $order
     * @param NotificationEntity $notification
     * @param string $orderTransactionId
     * @param array $logContext
     *
     * @return OrderTransactionEntity|null
     */
    private function getOrderTransactionFromMetadata(
        OrderEntity $order,
        NotificationEntity $notification,
        string $orderTransactionId,
        array $logContext
    ): ?OrderTransactionEntity {
        $orderTransaction = Uuid::isValid($orderTransactionId)
            ? $this->orderTransactionRepository->getWithId($orderTransactionId)
            : null;

        if (is_null($orderTransaction) ||
            is_null($orderTransaction->getOrder()) ||
            $orderTransaction->getOrder()->getOrderNumber() !== $order->getOrderNumber()) {
            $this->skipNotificationOfAnotherPayment($order, $notification, $logContext);

            return null;
        }

        // A stored payment response links the notification to the transaction in any state, as without the metadata,
        // e.g. the failed AUTHORISATION of a payment that /payments already returned as refused.
        if ($this->isPaymentResponseOfTransaction($notification->getPspreference(), $orderTransaction)) {
            return $orderTransaction;
        }

        $state = $orderTransaction->getStateMachineState()
            ? $orderTransaction->getStateMachineState()->getTechnicalName()
            : null;
        $canTakeNotification = in_array($state, self::WEBHOOK_TRANSACTION_STATES, true)
            ? !$this->belongsToAnotherPaymentAttempt($orderTransaction, $notification)
            : $this->isRecoverableFailedTransaction($orderTransaction, $notification);

        if (!$canTakeNotification) {
            $errorMessage = sprintf(
                'Skipped: Order transaction of order %s from the payment metadata cannot take the notification.',
                $order->getOrderNumber()
            );
            $this->logger->error($errorMessage, $logContext + ['transactionState' => $state]);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());

            return null;
        }

        return $orderTransaction;
    }

    /**
     * A capture, refund or another modification without the payment metadata, e.g. of a payment made before the
     * metadata was sent, belongs to another payment if it modifies a payment that is neither the payment of the
     * transaction, nor one of its partial payments, nor a payment the /payments or /payments/details call returned
     * for it. Without a known payment of the transaction, the notification is processed as before.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function isModificationOfAnotherPayment(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        $originalReference = $notification->getOriginalReference();
        $paymentPspReference =
            $orderTransaction->getCustomFields()[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] ?? null;

        if (empty($originalReference) || empty($paymentPspReference) || $originalReference === $paymentPspReference) {
            return false;
        }

        return !$this->adyenPaymentService->isPaymentOfOrderTransaction($originalReference, $orderTransaction->getId())
            && !$this->isPaymentResponseOfTransaction($originalReference, $orderTransaction);
    }

    /**
     * Whether the /payments or /payments/details call of the transaction returned the PSP reference.
     *
     * @param string|null $pspReference
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return bool
     */
    private function isPaymentResponseOfTransaction(
        ?string $pspReference,
        OrderTransactionEntity $orderTransaction
    ): bool {
        if (empty($pspReference)) {
            return false;
        }

        $paymentResponse = $this->paymentResponseService->getWithPspreference($pspReference);

        return !is_null($paymentResponse) && $paymentResponse->getOrderTransactionId() === $orderTransaction->getId();
    }

    /**
     * @param OrderEntity $order
     * @param NotificationEntity $notification
     * @param array $logContext
     *
     * @return void
     */
    private function skipNotificationOfAnotherPayment(
        OrderEntity $order,
        NotificationEntity $notification,
        array $logContext
    ): void {
        $errorMessage = sprintf(
            'Skipped: Notification belongs to a payment of another shop or transaction with order number %s.',
            $order->getOrderNumber()
        );
        $this->logger->warning($errorMessage, $logContext);
        $this->logNotificationFailure($notification, $errorMessage);
        $this->markAsDone($notification->getId(), $notification->getMerchantReference());
    }

    /**
     * @param NotificationEntity $notification
     *
     * @return string|null Order transaction ID from the metadata of the payment request, null if it was not sent
     */
    private function getMetadataOrderTransactionId(NotificationEntity $notification): ?string
    {
        $additionalData = json_decode($notification->getAdditionalData() ?? '', true);
        $orderTransactionId =
            $additionalData['metadata.' . PaymentRequestService::METADATA_ORDER_TRANSACTION_ID] ?? null;

        return is_string($orderTransactionId) && $orderTransactionId !== '' ? $orderTransactionId : null;
    }

    /**
     * A failed transaction can be settled by a successful AUTHORISATION or ORDER_CLOSED notification only if
     * it failed because the /payments/details call failed, not because the payment was refused or cancelled.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function isRecoverableFailedTransaction(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        $stateMachineState = $orderTransaction->getStateMachineState();

        return $stateMachineState
            && $stateMachineState->getTechnicalName() === OrderTransactionStates::STATE_FAILED
            && $this->canSettleFailedTransaction($notification)
            && $this->hasPaymentDetailsCallFailedMarker($orderTransaction);
    }

    /**
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function canSettleFailedTransaction(NotificationEntity $notification): bool
    {
        return $notification->isSuccess() && in_array(
            $notification->getEventCode(),
            [EventCodes::AUTHORISATION, EventCodes::ORDER_CLOSED],
            true
        );
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return bool
     */
    private function hasPaymentDetailsCallFailedMarker(OrderTransactionEntity $orderTransaction): bool
    {
        return !empty($orderTransaction->getCustomFields()[ResultHandler::PAYMENT_DETAILS_CALL_FAILED]);
    }

    /**
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return bool
     */
    private function isLatestOrderTransaction(OrderEntity $order, OrderTransactionEntity $orderTransaction): bool
    {
        $createdAt = $orderTransaction->getCreatedAt();
        if (is_null($order->getTransactions()) || is_null($createdAt)) {
            return true;
        }

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->getId() !== $orderTransaction->getId() &&
                !is_null($transaction->getCreatedAt()) &&
                $transaction->getCreatedAt() > $createdAt) {
                return false;
            }
        }

        return true;
    }

    /**
     * Moves the failed transaction back to in progress, so the notification is processed as for a transaction
     * whose /payments/details call succeeded.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param array $logContext
     *
     * @return void
     */
    private function reopenFailedTransaction(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        array $logContext
    ): void {
        $this->logger->info(
            'Reopening a transaction that failed because the /payments/details call failed.',
            $logContext
        );
        $this->orderTransactionStateHandler->process(
            $orderTransaction->getId(),
            $this->createContextWithoutFlows($context)
        );
    }

    /**
     * Fails a reopened transaction again and restores its marker if the notification did not settle it, e.g. the
     * amount did not match, it was one part of a split payment, or processing threw. A later notification for the
     * same payment can then still settle it.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param array $logContext
     *
     * @return void
     */
    private function restoreUnsettledTransaction(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        array $logContext
    ): void {
        $transaction = $this->orderTransactionRepository->getWithId($orderTransaction->getId());
        if (is_null($transaction) || is_null($transaction->getStateMachineState())) {
            return;
        }

        $state = $transaction->getStateMachineState()->getTechnicalName();

        if ($state === OrderTransactionStates::STATE_IN_PROGRESS) {
            $this->logger->info('Reopened transaction was not settled. Setting it back to failed.', $logContext);
            $this->orderTransactionStateHandler->fail(
                $transaction->getId(),
                $this->createContextWithoutFlows($context)
            );
        } elseif ($state !== OrderTransactionStates::STATE_FAILED) {
            return;
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $customFields[ResultHandler::PAYMENT_DETAILS_CALL_FAILED] = true;
        $transaction->setCustomFields($customFields);
        $this->orderTransactionRepository->updateCustomFields($transaction);
    }

    /**
     * Reopening and failing again are technical transitions, so they must not trigger flows such as the
     * "payment failed" mail. A copy is used, so the state does not stay on the context shared by all notifications.
     *
     * @param Context $context
     *
     * @return Context
     */
    private function createContextWithoutFlows(Context $context): Context
    {
        $contextWithoutFlows = clone $context;
        $contextWithoutFlows->addState(Context::SKIP_TRIGGER_FLOW);

        return $contextWithoutFlows;
    }

    /**
     * PSP reference of the payment the notification belongs to. An AUTHORISATION notification carries it in
     * pspReference and has no original reference, the other events (capture, refund, ...) carry it in
     * originalReference. A failed authorisation and a partial payment of an Adyen order are not the payment of
     * the transaction, so they give no reference. The reference of an AUTHORISATION is only taken if the
     * notification is linked to the transaction by the payment metadata or by a stored payment response, as one
     * linked only by the order number can be the payment of another shop that uses the same merchant account.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return string|null
     */
    private function getPaymentPspReference(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): ?string {
        if ($notification->getEventCode() !== EventCodes::AUTHORISATION) {
            return $notification->getOriginalReference();
        }

        if (!$notification->isSuccess() || AuthorisationWebhookHandler::isPartialPayment($notification)) {
            return null;
        }

        $isLinkedToTransaction =
            $this->getMetadataOrderTransactionId($notification) === $orderTransaction->getId() ||
            $this->isPaymentResponseOfTransaction($notification->getPspreference(), $orderTransaction);

        return $isLinkedToTransaction ? $notification->getPspreference() : null;
    }

    /**
     * A failed AUTHORISATION notification belongs to another payment attempt if the transaction already has
     * a different PSP reference, or if the transaction was created after the notification was received.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function belongsToAnotherPaymentAttempt(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        if ($notification->getEventCode() !== EventCodes::AUTHORISATION || $notification->isSuccess()) {
            return false;
        }

        $originalPspReference =
            $orderTransaction->getCustomFields()[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] ?? null;
        if (!empty($originalPspReference) && $originalPspReference !== $notification->getPspreference()) {
            return true;
        }

        $transactionCreatedAt = $orderTransaction->getCreatedAt();
        $notificationCreatedAt = $notification->getCreatedAt();

        return !is_null($transactionCreatedAt) &&
            !is_null($notificationCreatedAt) &&
            $transactionCreatedAt > $notificationCreatedAt;
    }

    /**
     * @param NotificationEntity $notification
     * @param Context $context
     * @param array $logContext
     * @return OrderEntity|null
     */
    private function getOrder(
        NotificationEntity $notification,
        Context $context,
        array $logContext
    ): ?OrderEntity {
        if ($notification->getEventCode() === EventCodes::ORDER_CLOSED) {
            // get merchant reference from adyen_payment table
            $merchantOrderReference = $notification->getMerchantReference();
            $merchantReference = $this->adyenPaymentService->getMerchantReferenceFromOrderReference(
                $merchantOrderReference
            );
        } else {
            // otherwise get the merchant reference from the notification
            $merchantReference = $notification->getMerchantReference();
        }

        if ($merchantReference === null) {
            $errorMessage = "Skipped: Order with order_number {$notification->getMerchantReference()} not found.";
            $this->logger->error($errorMessage, $logContext);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());
            return null;
        }

        $order = $this->orderRepository->getOrderByOrderNumber(
            $merchantReference,
            $context,
            ['transactions', 'currency']
        );

        if (!$order) {
            $processingResult = $this->paypalPaymentService->handleNotificationWithoutOrder(
                $notification,
                $merchantReference,
                $logContext
            );
            if ($processingResult) {
                $this->applyProcessingResult($notification, $processingResult);
                return null;
            }

            $errorMessage = "Skipped: Order with order_number {$notification->getMerchantReference()} not found.";
            $this->logger->error($errorMessage, $logContext);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());
            return null;
        } else {
            return $order;
        }
    }

    /**
     * @param NotificationEntity $notification
     * @param NotificationProcessingResult $processingResult
     * @return void
     */
    private function applyProcessingResult(
        NotificationEntity $notification,
        NotificationProcessingResult $processingResult
    ): void {
        if ($processingResult->getError() !== null) {
            $this->logNotificationFailure($notification, $processingResult->getError());
        }

        switch ($processingResult->getAction()) {
            case NotificationProcessingResult::ACTION_RESCHEDULE:
                $this->rescheduleNotification(
                    $notification->getId(),
                    $notification->getMerchantReference(),
                    $processingResult->getScheduledProcessingTime()
                );
                return;
            case NotificationProcessingResult::ACTION_RETRY:
                if ($notification->getErrorCount() < self::MAX_ERROR_COUNT) {
                    $this->rescheduleNotification($notification->getId(), $notification->getMerchantReference());
                    return;
                }
                break;
        }

        $this->markAsDone($notification->getId(), $notification->getMerchantReference());
    }

    /**
     * @param string $notificationId
     * @param string $merchantReference
     * @return void
     */
    private function markAsProcessing(string $notificationId, string $merchantReference): void
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', true);
        $this->logger->debug("Payment notification for order {$merchantReference} marked as processing.");
    }

    /**
     * @param string $notificationId
     * @param string|null $merchantReference
     * @return void
     */
    private function markAsDone(string $notificationId, ?string $merchantReference = null): void
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->changeNotificationState($notificationId, 'done', true);
        $this->logger->debug("Payment notification {$notificationId} for order {$merchantReference} marked as done.");
    }

    /**
     * @param string $notificationId
     * @param string|null $merchantReference
     * @param \DateTime|null $dateTime
     * @return void
     */
    private function rescheduleNotification(
        string $notificationId,
        ?string $merchantReference = null,
        ?\DateTime $dateTime = null
    ) {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->setNotificationSchedule($notificationId, $dateTime ?? new \DateTime());
        $this->logger->debug("Payment notification {$notificationId} for order {$merchantReference} rescheduled.");
    }

    /**
     * set notification error and increment error count
     * @param NotificationEntity $notification
     * @param string $errorMessage
     * @return void
     */
    private function logNotificationFailure(NotificationEntity $notification, string $errorMessage): void
    {
        $errorCount = (int) $notification->getErrorCount();
        $this->notificationService
            ->saveError($notification->getId(), $errorMessage, ++$errorCount);
    }
}
