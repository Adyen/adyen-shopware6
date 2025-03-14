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
use Adyen\Shopware\ScheduledTask\Webhook\WebhookHandlerFactory;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\PaymentResponseService;
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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ProcessNotifications::class)]
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

    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentMethodRepository;

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
     * @param EntityRepository $paymentMethodRepository
     * @param OrderTransactionRepository $orderTransactionRepository
     * @param AdyenPaymentService $adyenPaymentService
     * @param CaptureService $captureService
     * @param WebhookHandlerFactory $webhookHandlerFactory
     * @param PaymentResponseService $paymentResponseService
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        EntityRepository $paymentMethodRepository,
        OrderTransactionRepository $orderTransactionRepository,
        AdyenPaymentService $adyenPaymentService,
        CaptureService $captureService,
        WebhookHandlerFactory $webhookHandlerFactory,
        PaymentResponseService $paymentResponseService
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->adyenPaymentService = $adyenPaymentService;
        $this->captureService = $captureService;
        $this->paymentResponseService = $paymentResponseService;
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

                $customFields = $orderTransaction->getCustomFields();

                if (empty($customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE])) {
                    $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] =
                        $notification->getOriginalReference();
                    $orderTransaction->setCustomFields($customFields);
                    $this->orderTransactionRepository->updateCustomFields($orderTransaction);
                }

                $currentTransactionState = $this->getCurrentTransactionState($orderTransaction, $notification);
                if (is_null($currentTransactionState)) {
                    continue;
                }

                $processor = $this->createProcessor($notification, $currentTransactionState);
                if (is_null($processor)) {
                    continue;
                }

                $state = $processor->process();

                $this->logger->info('Processed ' . $notification->getEventCode() . ' notification.', [
                    'eventCode' => $notification->getEventCode(),
                    'originalState' => $currentTransactionState,
                    'newState' => $state
                ]);

                $webhookHandler->handleWebhook(
                    $orderTransaction,
                    $notification,
                    $state,
                    $currentTransactionState,
                    $context
                );
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
            $notificationItem = Notification::createItem([
                'eventCode' => $notification->getEventCode(),
                'success' => $notification->isSuccess()
            ]);

            $isAutoCapture = !($this->captureService->isManualCaptureActive()
                || $this->captureService->isCaptureOnShipmentEnabled());

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
        } else {
            return $orderTransaction;
        }
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

        $order = $this->orderRepository->getOrderByOrderNumber(
            $merchantReference,
            $context,
            ['transactions', 'currency']
        );

        if (!$order) {
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
