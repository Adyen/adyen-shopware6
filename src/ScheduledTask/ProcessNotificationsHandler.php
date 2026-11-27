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
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaypalPaymentService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Util\Currency;
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
use Shopware\Core\Checkout\Order\OrderStates;
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
     * Capture retry interval used when the configured one cannot be read, the same as the old default.
     */
    private const DEFAULT_CAPTURE_RETRY_SECONDS = 43200;

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
            // Reset per notification, the catch blocks below use them.
            $logContext = [];
            $order = null;
            $orderTransaction = null;

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

                $isLinkedToTransaction = false;
                $orderTransaction = $this->getOrderTransaction(
                    $order,
                    $notification,
                    $logContext,
                    $isLinkedToTransaction
                );
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
                        ['transactions', 'currency', 'stateMachineState']
                    );
                    if (is_null($order)) {
                        $errorMessage = 'Skipped: Order of the order transaction from the payment metadata not found.';
                        $this->logger->error(
                            $errorMessage,
                            $logContext + ['transactionOrderId' => $transactionOrderId]
                        );
                        $this->logNotificationFailure($notification, $errorMessage);
                        $this->markAsDone($notification->getId(), $notification->getMerchantReference());
                        continue;
                    }
                    $logContext['orderId'] = $order->getId();
                }

                $hadPaymentDetailsCallFailedMarker = $this->hasPaymentDetailsCallFailedMarker($orderTransaction);
                $isNewPayment = $this->isNewPaymentOfFailedTransaction($orderTransaction, $notification);
                $isRecoverable = $this->isRecoverableFailedTransaction($orderTransaction, $notification);
                if ($isRecoverable && !$this->canOrderTakeRecoveredPayment($order, $orderTransaction)) {
                    $errorMessage = 'Skipped: Payment was authorised for a failed transaction that the order can ' .
                        'no longer take, because a newer transaction replaced it, another transaction settled the ' .
                        'order or the order was cancelled. Check whether the payment has to be refunded.';
                    $this->logger->warning(
                        $errorMessage,
                        $logContext + ['pspReference' => $notification->getPspreference()]
                    );
                    $this->logNotificationFailure($notification, $errorMessage);
                    $this->markAsDone($notification->getId(), $notification->getMerchantReference());
                    continue;
                }

                if ($isRecoverable && $isNewPayment &&
                    is_null($this->getMetadataOrderTransactionId($notification))) {
                    $this->logger->warning(
                        'Applying a new payment to a failed transaction whose payment was refused. The payment ' .
                        'has no metadata and is linked to the transaction by the order number, amount and currency.',
                        $logContext + ['pspReference' => $notification->getPspreference()]
                    );
                }

                $customFields = $orderTransaction->getCustomFields();
                $customFieldsChanged = false;

                // Needed for capture and refund when the shopper did not return to the shop after the payment.
                // The key is set even without a reference, as the refund only checks that it exists.
                $paymentPspReference =
                    $this->getPaymentPspReference($orderTransaction, $notification, $isLinkedToTransaction);
                $storedPspReference = $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] ?? null;
                if (empty($storedPspReference) || $this->replacesUnknownPspReference(
                    $orderTransaction,
                    $notification,
                    $storedPspReference,
                    $paymentPspReference
                )) {
                    $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] = $paymentPspReference;
                    $customFieldsChanged = true;
                }

                // Capture and refund must use the new payment, not the refused one of the transaction.
                if ($isRecoverable && $isNewPayment) {
                    $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] = $notification->getPspreference();
                    $customFieldsChanged = true;
                }

                // The marker is cleared, so the transaction is reopened only once.
                if ($isRecoverable && $hadPaymentDetailsCallFailedMarker) {
                    $customFields[ResultHandler::PAYMENT_DETAILS_CALL_FAILED] = null;
                    $customFieldsChanged = true;
                }

                // Restored if the notification does not settle the reopened transaction, so a retry of the
                // notification can still settle it.
                $customFieldsToRestore = [];
                if ($hadPaymentDetailsCallFailedMarker) {
                    $customFieldsToRestore[ResultHandler::PAYMENT_DETAILS_CALL_FAILED] = true;
                }
                if ($isNewPayment) {
                    $customFieldsToRestore[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] = $storedPspReference;
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
                        $orderTransaction = $this->reopenFailedTransaction($orderTransaction, $context, $logContext);
                    }

                    $processor = $this->createProcessor(
                        $notification,
                        $currentTransactionState,
                        $orderTransaction,
                        $order->getSalesChannelId()
                    );
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
                        $this->restoreUnsettledTransaction(
                            $orderTransaction,
                            $context,
                            $logContext,
                            $customFieldsToRestore
                        );
                    }
                }
            } catch (CaptureException $exception) {
                if (CaptureService::REASON_NOTHING_TO_CAPTURE === $exception->reason) {
                    // Captured manually in the meantime, or cancelled: there is nothing left to do.
                    $this->logger->info($exception->getMessage(), $logContext);
                    $this->markAsDone($notification->getId(), $notification->getMerchantReference());

                    continue;
                }

                $this->logger->warning($exception->getMessage(), ['code' => $exception->getCode()]);
                if (CaptureService::REASON_DELIVERY_STATE_MISMATCH === $exception->reason ||
                    CaptureService::REASON_WAITING_AUTH_WEBHOOK === $exception->reason) {
                    $this->rescheduleNotification(
                        $notification->getId(),
                        $notification->getMerchantReference(),
                        $this->getCaptureRetryTime($orderTransaction, $order, $logContext)
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
     * @param OrderTransactionEntity $orderTransaction
     * @param string|null $salesChannelId
     *
     * @return ProcessorInterface|null
     */
    private function createProcessor(
        NotificationEntity $notification,
        string $currentTransactionState,
        OrderTransactionEntity $orderTransaction,
        ?string $salesChannelId
    ): ?ProcessorInterface {
        try {
            // The additional data is needed for CANCEL_OR_REFUND, it tells whether a cancellation or a refund was done
            $additionalData = json_decode($notification->getAdditionalData() ?? '', true);
            $notificationItem = Notification::createItem([
                'eventCode' => $notification->getEventCode(),
                'success' => $notification->isSuccess(),
                'additionalData' => is_array($additionalData) ? $additionalData : []
            ]);

            // The capture mode of this payment, not the global one.
            $isAutoCapture = !$this->captureService
                ->getCaptureConfiguration($orderTransaction, $salesChannelId)
                ->isDeferredCapture();

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
     * @param bool $isLinkedToTransaction Set to whether the notification is linked to the transaction by the payment
     *                                    metadata or by a stored payment response, not only by the order number.
     *
     * @return OrderTransactionEntity|null
     */
    private function getOrderTransaction(
        OrderEntity $order,
        NotificationEntity $notification,
        array $logContext,
        bool &$isLinkedToTransaction
    ): ?OrderTransactionEntity {
        $orderTransaction = null;
        $isLinkedToTransaction = false;

        // Several shops can use the same merchant account and have orders with the same order number, and Adyen
        // sends the webhooks of all of them to every shop. The webhooks of a payment carry its metadata.
        $metadataTransactionId = $this->getMetadataOrderTransactionId($notification);
        if (!is_null($metadataTransactionId)) {
            $orderTransaction = $this->getOrderTransactionFromMetadata(
                $order,
                $notification,
                $metadataTransactionId,
                $logContext
            );
            $isLinkedToTransaction = !is_null($orderTransaction);

            return $orderTransaction;
        }

        /* Fetch the related order_transaction entity with the given pspreference.
         * There might be several order transactions if there have been multiple payment attempts. */
        $adyenPaymentResponse = $this->paymentResponseService->getWithPspreference($notification->getPspreference());
        if (isset($adyenPaymentResponse)) {
            $orderTransaction = $this->orderTransactionRepository->getWithId(
                $adyenPaymentResponse->getOrderTransactionId()
            );
            $isLinkedToTransaction = !is_null($orderTransaction);
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

        $isNewPaymentOfFailedTransaction = false;
        if (is_null($orderTransaction) && $this->canSettleFailedTransaction($notification)) {
            $orderTransaction = $this->orderTransactionRepository
                ->getAdyenOrderTransactionsByStates($order->getId(), [OrderTransactionStates::STATE_FAILED])
                ->filter(fn (OrderTransactionEntity $transaction) => $this->isRecoverableFailedTransaction(
                    $transaction,
                    $notification
                ))
                ->first();
            $isNewPaymentOfFailedTransaction = !is_null($orderTransaction)
                && $this->isNewPaymentOfFailedTransaction($orderTransaction, $notification);
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

        // The stored payment of a transaction whose payment was refused cannot be authorised anymore, so a new payment
        // is not another payment of the transaction.
        if (!$isLinkedToTransaction && !$isNewPaymentOfFailedTransaction &&
            $this->isAuthorisationOfAnotherPayment($orderTransaction, $notification)) {
            if (!$this->rescheduleUntilRefusalIsProcessed($orderTransaction, $notification, $logContext)) {
                $this->skipNotificationOfAnotherPayment($order, $notification, $logContext);
            }

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

        // The payment method of the transaction can be changed to another one in the administration.
        if (!$this->isAdyenPaymentMethod($orderTransaction)) {
            $errorMessage = sprintf(
                'Skipped: Order transaction of order %s from the payment metadata is not an Adyen payment.',
                $order->getOrderNumber()
            );
            $this->logger->error($errorMessage, $logContext);
            $this->logNotificationFailure($notification, $errorMessage);
            $this->markAsDone($notification->getId(), $notification->getMerchantReference());

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
     * An AUTHORISATION without the payment metadata, e.g. from a shop with an older plugin version, belongs to
     * another payment if it is linked to the transaction only by the order number, while the /payments or
     * /payments/details call of the transaction returned another PSP reference. A partial payment of an Adyen order
     * has its own PSP reference. Without a returned PSP reference, the notification is processed as before.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function isAuthorisationOfAnotherPayment(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        if ($notification->getEventCode() !== EventCodes::AUTHORISATION ||
            AuthorisationWebhookHandler::isPartialPayment($notification)) {
            return false;
        }

        // A payment already stored for the transaction, e.g. a new payment that settled the failed transaction and
        // whose notification is retried for the capture on shipment.
        if (!empty($notification->getPspreference()) && $this->adyenPaymentService->isPaymentOfOrderTransaction(
            $notification->getPspreference(),
            $orderTransaction->getId()
        )) {
            return false;
        }

        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction);

        return !is_null($paymentResponse) && !empty($paymentResponse->getPspreference());
    }

    /**
     * A refused AUTHORISATION is processed only after a delay, so a new payment, e.g. by link, can arrive while the
     * transaction still waits for the refusal of its payment. The new payment is then processed after the refusal
     * failed the transaction, instead of being skipped as another payment.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @param array $logContext
     *
     * @return bool Whether the notification was rescheduled.
     */
    private function rescheduleUntilRefusalIsProcessed(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification,
        array $logContext
    ): bool {
        if (!$notification->isSuccess()) {
            return false;
        }

        $pendingRefusal = $this->notificationService->getPendingFailedAuthorisation(
            (string)$notification->getMerchantReference(),
            $this->getKnownPaymentPspReferences($orderTransaction)
        );
        if (is_null($pendingRefusal)) {
            return false;
        }

        $refusalProcessingTime = $pendingRefusal->getScheduledProcessingTime()
            ?? $this->notificationService->calculateScheduledProcessingTime($pendingRefusal);
        $processingTime = new \DateTime('@' . ($refusalProcessingTime ?? new \DateTime())->getTimestamp());
        $processingTime->add(new \DateInterval('PT1M'));
        $earliestProcessingTime = (new \DateTime())->add(new \DateInterval('PT1M'));
        if ($processingTime < $earliestProcessingTime) {
            $processingTime = $earliestProcessingTime;
        }

        $this->logger->info(
            'Rescheduled a new payment until the refused payment of the transaction is processed.',
            $logContext + [
                'pspReference' => $notification->getPspreference(),
                'refusedPspReference' => $pendingRefusal->getPspreference(),
            ]
        );
        $this->rescheduleNotification(
            $notification->getId(),
            $notification->getMerchantReference(),
            $processingTime
        );

        return true;
    }

    /**
     * A capture, refund or another modification without the payment metadata, e.g. of a payment made before the
     * metadata was sent, belongs to another payment if it modifies a payment that is not a known payment of the
     * transaction, while the stored PSP reference of the transaction is one. A reference stored from a modification
     * is not known, e.g. one of several gift cards, so then the notification is processed as before.
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
        // An AUTHORISATION has no original reference.
        if ($notification->getEventCode() === EventCodes::AUTHORISATION) {
            return false;
        }

        $originalReference = $notification->getOriginalReference();
        $paymentPspReference =
            $orderTransaction->getCustomFields()[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] ?? null;

        if (empty($originalReference) || empty($paymentPspReference) || $originalReference === $paymentPspReference) {
            return false;
        }

        return $this->isKnownPaymentOfTransaction($paymentPspReference, $orderTransaction)
            && !$this->isKnownPaymentOfTransaction($originalReference, $orderTransaction);
    }

    /**
     * Whether the PSP reference is a payment of the transaction: a payment of its adyen_payment rows, e.g. a partial
     * payment of an Adyen order, or one the /payments or /payments/details call returned.
     *
     * @param string $pspReference
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return bool
     */
    private function isKnownPaymentOfTransaction(string $pspReference, OrderTransactionEntity $orderTransaction): bool
    {
        return $this->adyenPaymentService->isPaymentOfOrderTransaction($pspReference, $orderTransaction->getId())
            || $this->isPaymentResponseOfTransaction($pspReference, $orderTransaction);
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return bool
     */
    private function isAdyenPaymentMethod(OrderTransactionEntity $orderTransaction): bool
    {
        $paymentMethod = $orderTransaction->getPaymentMethod();
        $plugin = $paymentMethod ? $paymentMethod->getPlugin() : null;

        return $plugin && $plugin->getName() === ConfigurationService::BUNDLE_NAME;
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
     * A failed transaction can be settled by a successful AUTHORISATION or ORDER_CLOSED notification if it failed
     * because the /payments/details call failed, so the outcome of its payment was unknown, or by a new payment of
     * the transaction, see isNewPaymentOfFailedTransaction().
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
            && ($this->hasPaymentDetailsCallFailedMarker($orderTransaction)
                || $this->isNewPaymentOfFailedTransaction($orderTransaction, $notification));
    }

    /**
     * A successful AUTHORISATION of another payment than the ones of the failed transaction, for the full amount, e.g.
     * after the payment of the transaction was refused. It belongs to the transaction if the payment metadata links it
     * to the transaction. Without the metadata, e.g. a payment by link or of an older plugin version, it is linked only
     * by the order number, so the refused payment of the transaction must be known: a refused payment cannot be
     * authorised later, so the new payment is no other payment of an attempt that is still open.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function isNewPaymentOfFailedTransaction(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        $stateMachineState = $orderTransaction->getStateMachineState();
        if (is_null($stateMachineState) ||
            $stateMachineState->getTechnicalName() !== OrderTransactionStates::STATE_FAILED ||
            $notification->getEventCode() !== EventCodes::AUTHORISATION ||
            !$notification->isSuccess() ||
            AuthorisationWebhookHandler::isPartialPayment($notification)) {
            return false;
        }

        $knownPspReferences = $this->getKnownPaymentPspReferences($orderTransaction);
        if (in_array($notification->getPspreference(), $knownPspReferences, true) ||
            !$this->isFullAmountOfTransaction($orderTransaction, $notification)) {
            return false;
        }

        // A notification with metadata reaches here only for the transaction of its metadata.
        if (!is_null($this->getMetadataOrderTransactionId($notification))) {
            return true;
        }

        return $this->notificationService->hasProcessedFailedAuthorisation(
            (string)$notification->getMerchantReference(),
            $knownPspReferences
        );
    }

    /**
     * PSP references of the payment of the transaction: the stored one and the one the /payments or /payments/details
     * call returned.
     *
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return string[]
     */
    private function getKnownPaymentPspReferences(OrderTransactionEntity $orderTransaction): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction);
        $pspReferences = [
            $orderTransaction->getCustomFields()[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] ?? null,
            $paymentResponse ? $paymentResponse->getPspreference() : null,
        ];

        return array_values(array_unique(array_filter($pspReferences)));
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     *
     * @return bool
     */
    private function isFullAmountOfTransaction(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        $order = $orderTransaction->getOrder();
        $currency = $order ? $order->getCurrency() : null;
        if (is_null($currency) || $currency->getIsoCode() !== $notification->getAmountCurrency()) {
            return false;
        }

        $transactionAmount = (new Currency())->sanitize(
            $orderTransaction->getAmount()->getTotalPrice(),
            $currency->getIsoCode()
        );

        return $transactionAmount === (int)$notification->getAmountValue();
    }

    /**
     * A failed transaction can be settled only if it is still the payment of the order: no newer transaction replaced
     * it, no other transaction settled the order and the order was not cancelled.
     *
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return bool
     */
    private function canOrderTakeRecoveredPayment(OrderEntity $order, OrderTransactionEntity $orderTransaction): bool
    {
        $orderState = $order->getStateMachineState();
        if (($orderState && $orderState->getTechnicalName() === OrderStates::STATE_CANCELLED) ||
            !$this->isLatestOrderTransaction($order, $orderTransaction)) {
            return false;
        }

        $settledTransactions = $this->orderTransactionRepository->getAdyenOrderTransactionsByStates(
            $order->getId(),
            [
                OrderTransactionStates::STATE_PAID,
                OrderTransactionStates::STATE_PARTIALLY_PAID,
                OrderTransactionStates::STATE_AUTHORIZED,
            ]
        );

        return $settledTransactions->filter(
            fn (OrderTransactionEntity $transaction) => $transaction->getId() !== $orderTransaction->getId()
        )->count() === 0;
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
     * whose payment is still open.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param array $logContext
     *
     * @return OrderTransactionEntity The reloaded transaction. The state handler changes only the database, so the
     *     given entity would still be failed, and the webhook handlers decide by its state.
     */
    private function reopenFailedTransaction(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        array $logContext
    ): OrderTransactionEntity {
        $this->logger->info('Reopening a failed transaction to settle it with a successful payment.', $logContext);
        $this->orderTransactionStateHandler->process(
            $orderTransaction->getId(),
            $this->createContextWithoutFlows($context)
        );

        return $this->orderTransactionRepository->getWithId($orderTransaction->getId()) ?? $orderTransaction;
    }

    /**
     * Fails a reopened transaction again and restores its custom fields if the notification did not settle it, e.g.
     * the amount did not match, it was one part of a split payment, or processing threw. A later notification or a
     * retry of the same notification can then still settle it: the marker is restored, and for a new payment the
     * PSP reference of the refused payment, as the new one would otherwise count as a payment of the transaction.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param array $logContext
     * @param array $customFieldsToRestore
     *
     * @return void
     */
    private function restoreUnsettledTransaction(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        array $logContext,
        array $customFieldsToRestore
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

        if (empty($customFieldsToRestore)) {
            return;
        }

        $transaction->setCustomFields(array_merge($transaction->getCustomFields() ?? [], $customFieldsToRestore));
        $this->orderTransactionRepository->updateCustomFields($transaction);
    }

    /**
     * Next capture attempt, after the retry interval of the payment's capture configuration. Called from the catch
     * block, so it must never throw: an exception there would stop the processing of all remaining notifications.
     *
     * @param OrderTransactionEntity|null $orderTransaction
     * @param OrderEntity|null $order
     * @param array $logContext
     *
     * @return \DateTime
     */
    private function getCaptureRetryTime(
        ?OrderTransactionEntity $orderTransaction,
        ?OrderEntity $order,
        array $logContext
    ): \DateTime {
        try {
            return $this->captureService->getRescheduleNotificationTime(
                $orderTransaction !== null && $order !== null
                    ? $this->captureService->getCaptureConfiguration($orderTransaction, $order->getSalesChannelId())
                    : null
            );
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Could not read the capture retry interval, the default interval is used.',
                $logContext + ['errorMessage' => $exception->getMessage()]
            );

            return (new \DateTime())->add(new \DateInterval('PT' . self::DEFAULT_CAPTURE_RETRY_SECONDS . 'S'));
        }
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
     * the transaction, so they give no reference. A reference is only taken if the notification is linked to the
     * transaction by the payment metadata or by a stored payment response, or if it is a known payment of the
     * transaction, as a notification linked only by the order number can be of another shop that uses the same
     * merchant account.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @param bool $isLinkedToTransaction
     *
     * @return string|null
     */
    private function getPaymentPspReference(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification,
        bool $isLinkedToTransaction
    ): ?string {
        if ($notification->getEventCode() !== EventCodes::AUTHORISATION) {
            $originalReference = $notification->getOriginalReference();
            $isPaymentOfTransaction = $isLinkedToTransaction || (!empty($originalReference)
                && $this->isKnownPaymentOfTransaction($originalReference, $orderTransaction));

            return $isPaymentOfTransaction ? $originalReference : null;
        }

        if (!$notification->isSuccess() || AuthorisationWebhookHandler::isPartialPayment($notification)) {
            return null;
        }

        return $isLinkedToTransaction ? $notification->getPspreference() : null;
    }

    /**
     * The PSP reference of an AUTHORISATION linked to the transaction replaces a stored PSP reference that is not a
     * known payment of the transaction, e.g. one stored before from a capture or refund of another shop that uses
     * the same merchant account, so capture and refund use the payment of the transaction.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @param string $storedPspReference
     * @param string|null $paymentPspReference From getPaymentPspReference(), only set for a linked payment.
     *
     * @return bool
     */
    private function replacesUnknownPspReference(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification,
        string $storedPspReference,
        ?string $paymentPspReference
    ): bool {
        return $notification->getEventCode() === EventCodes::AUTHORISATION
            && !empty($paymentPspReference)
            && $paymentPspReference !== $storedPspReference
            && !$this->isKnownPaymentOfTransaction($storedPspReference, $orderTransaction);
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
            ['transactions', 'currency', 'stateMachineState']
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
