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
use Adyen\Shopware\ScheduledTask\Webhook\WebhookHandlerFactory;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Notification;
use Adyen\Webhook\PaymentStates;
use Adyen\Webhook\Processor\ProcessorFactory;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

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
    private $notificationService;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;

    /**
     * @var array|null
     */
    private $adyenPaymentMethodIds = null;

    /**
     * @var OrderTransactionRepository
     */
    private $orderTransactionRepository;

    /**
     * @var CaptureService
     */
    private $captureService;

    /**
     * @var WebhookHandlerFactory
     */
    private static $webhookHandlerFactory;

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
     * @param EntityRepositoryInterface $scheduledTaskRepository
     * @param NotificationService $notificationService
     * @param OrderRepository $orderRepository
     * @param EntityRepositoryInterface $paymentMethodRepository
     * @param OrderTransactionRepository $orderTransactionRepository
     * @param CaptureService $captureService
     * @param WebhookHandlerFactory $webhookHandlerFactory
     */
    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        EntityRepositoryInterface $paymentMethodRepository,
        OrderTransactionRepository $orderTransactionRepository,
        CaptureService $captureService,
        WebhookHandlerFactory $webhookHandlerFactory
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->captureService = $captureService;
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
            /** @var NotificationEntity $notification */
            $logContext = ['eventCode' => $notification->getEventCode()];
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

            $currentTransactionState = $this->getCurrentTransactionState($orderTransaction, $notification);
            if (is_null($currentTransactionState)) {
                continue;
            }

            $processor = $this->createProcessor($notification, $currentTransactionState);
            if (is_null($processor)) {
                continue;
            }

            $state = $processor->process();

            try {
                $webhookHandler = self::$webhookHandlerFactory::create($notification->getEventCode());
                $webhookHandler->handleWebhook(
                    $orderTransaction,
                    $notification,
                    $state,
                    $currentTransactionState,
                    $context
                );
            } catch (CaptureException $exception) {
                $this->logger->warning($exception->getMessage(), ['code' => $exception->getCode()]);
                $this->logNotificationFailure($notification, $exception->getMessage());

                $scheduledProcessingTime = $this->captureService->getRescheduleNotificationTime();
                if ($notification->getErrorCount() < self::MAX_ERROR_COUNT) {
                    $this->rescheduleNotification(
                        $notification->getId(),
                        $notification->getMerchantReference(),
                        $scheduledProcessingTime
                    );
                } else {
                    $this->markAsDone($notification->getId(), $notification->getMerchantReference());
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
     * @param $notification
     * @param $currentTransactionState
     * @return \Adyen\Webhook\Processor\ProcessorInterface|null
     */
    private function createProcessor($notification, $currentTransactionState)
    {
        try {
            $notificationItem = Notification::createItem([
                'eventCode' => $notification->getEventCode(),
                'success' => $notification->isSuccess()
            ]);

            return ProcessorFactory::create(
                $notificationItem,
                $currentTransactionState,
                $this->logger
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
     * @param $orderTransaction
     * @param $notification
     * @return mixed|string|null
     */
    private function getCurrentTransactionState($orderTransaction, $notification)
    {
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
     * @param $order
     * @param $notification
     * @param $logContext
     * @return \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null
     */
    private function getOrderTransaction($order, $notification, $logContext)
    {
        $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
            $order->getId(),
            self::WEBHOOK_TRANSACTION_STATES
        );

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
     * @param $notification
     * @param $context
     * @param $logContext
     * @return \Shopware\Core\Checkout\Order\OrderEntity|null
     */
    private function getOrder($notification, $context, $logContext)
    {
        $order = $this->orderRepository->getOrderByOrderNumber(
            $notification->getMerchantReference(),
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
    private function markAsProcessing(string $notificationId, string $merchantReference)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', true);
        $this->logger->debug("Payment notification for order {$merchantReference} marked as processing.");
    }

    /**
     * @param string $notificationId
     * @param string $merchantReference
     * @return void
     */
    private function markAsDone(string $notificationId, string $merchantReference)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->changeNotificationState($notificationId, 'done', true);
        $this->logger->debug("Payment notification for order {$merchantReference} marked as done.");
    }

    /**
     * @param string $notificationId
     * @param string $merchantReference
     * @param \DateTime|null $dateTime
     * @return void
     */
    private function rescheduleNotification(
        string $notificationId,
        string $merchantReference,
        ?\DateTime $dateTime = null
    ) {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->setNotificationSchedule($notificationId, $dateTime ?? new \DateTime());
        $this->logger->debug("Payment notification for order {$merchantReference} rescheduled.");
    }

    /**
     * set notification error and increment error count
     * @param NotificationEntity $notification
     * @param string $errorMessage
     * @return void
     */
    private function logNotificationFailure(NotificationEntity $notification, string $errorMessage)
    {
        $errorCount = (int) $notification->getErrorCount();
        $this->notificationService
            ->saveError($notification->getId(), $errorMessage, ++$errorCount);
    }
}
