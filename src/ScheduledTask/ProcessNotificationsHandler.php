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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Provider\AdyenPluginProvider;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Util\Currency;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Notification;
use Adyen\Webhook\PaymentStates;
use Adyen\Webhook\Processor\ProcessorFactory;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

class ProcessNotificationsHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * @var NotificationService
     */
    private $notificationService;
    /**
     * @var OrderRepository
     */
    private $orderRepository;
    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;
    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;
    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;
    /**
     * @var array|null
     */
    private $adyenPaymentMethodIds = null;

    private const MAX_ERROR_COUNT = 3;
    /** @var array Mapping to convert Shopware transaction states to payment states in the webhook module. */
    private $webhookModuleStateMapping = [
        OrderTransactionStates::STATE_PAID => PaymentStates::STATE_PAID,
        OrderTransactionStates::STATE_FAILED => PaymentStates::STATE_FAILED,
        OrderTransactionStates::STATE_IN_PROGRESS => PaymentStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_REFUNDED => PaymentStates::STATE_REFUNDED,
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED => PaymentStates::STATE_PARTIALLY_REFUNDED,
    ];

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $paymentMethodRepository,
        AdyenPluginProvider $adyenPluginProvider
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->adyenPluginProvider = $adyenPluginProvider;
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

            $this->markAsProcessing($notification->getId());

            $order = $this->orderRepository->getOrderByOrderNumber(
                $notification->getMerchantReference(),
                $context,
                ['transactions', 'currency']
            );

            $logContext = ['eventCode' => $notification->getEventCode()];
            if (!$order) {
                $this->logger->warning(
                    "Order with order_number {$notification->getMerchantReference()} not found.",
                    $logContext
                );
                $this->markAsDone($notification->getId());
                continue;
            }
            $logContext['orderId'] = $order->getId();
            $logContext['orderNumber'] = $order->getOrderNumber();

            $orderTransaction = $order->getTransactions()->first();
            // Skip when the last payment method was non-Adyen.
            if (!$this->isAdyenPaymentMethod($orderTransaction->getPaymentMethodId(), $context)) {
                $this->logger->info('Notification ignored: non-Adyen payment method last used, .', $logContext);
                $this->markAsDone($notification->getId());
                continue;
            }
            $currentTransactionState = $this->webhookModuleStateMapping[
                $orderTransaction->getStateMachineState()->getTechnicalName()
            ] ?? '';
            try {
                $notificationItem = Notification::createItem([
                    'eventCode' => $notification->getEventCode(),
                    'success' => $notification->isSuccess()
                ]);

                $processor = ProcessorFactory::create(
                    $notificationItem,
                    $currentTransactionState,
                    $this->logger
                );
            } catch (InvalidDataException $exception) {
                $logContext['notification'] = get_object_vars($notification);
                $this->logger->error('Unable to process notification: Invalid notification data', $logContext);
                $this->markAsDone($notification->getId());
                continue;
            }

            $state = $processor->process();

            if ($state !== $currentTransactionState) {
                try {
                    $this->transitionToState($notification, $order, $state, $context);
                } catch (\Exception $exception) {
                    $logContext['errorMessage'] = $exception->getMessage();
                    // set notification error and increment error count
                    $errorCount = (int) $notification->getErrorCount();
                    $this->notificationService
                        ->saveError($notification->getId(), $exception->getMessage(), ++$errorCount);
                    $this->logger->error('Notification processing failed.', $logContext);

                    if ($errorCount < self::MAX_ERROR_COUNT) {
                        $this->requeueNotification($notification->getId());
                    }

                    continue;
                }
            }

            $this->markAsDone($notification->getId());
        }

        $this->logger->info('Processed ' . $notifications->count() . ' notifications.');
    }

    private function transitionToState(
        NotificationEntity $notification,
        OrderEntity $order,
        string $state,
        Context $context
    ) {
        $orderTransaction = $order->getTransactions()->first();
        switch ($state) {
            case PaymentStates::STATE_PAID:
                $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
                break;
            case PaymentStates::STATE_FAILED:
                $this->transactionStateHandler->fail($orderTransaction->getId(), $context);
                break;
            case PaymentStates::STATE_IN_PROGRESS:
                $this->transactionStateHandler->process($orderTransaction->getId(), $context);
                break;
            case PaymentStates::STATE_REFUNDED:
                // Determine whether refund was full or partial.
                $refundedAmount = (int) $notification->getAmountValue();

                $currencyUtil = new Currency();
                $totalPrice = $orderTransaction->getAmount()->getTotalPrice();
                $isoCode = $order->getCurrency()->getIsoCode();
                $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

                if ($refundedAmount > $transactionAmount) {
                    throw new \Exception('The refunded amount is greater than the transaction amount.');
                }

                $transitionState = $refundedAmount < $transactionAmount
                    ? OrderTransactionStates::STATE_PARTIALLY_REFUNDED
                    : OrderTransactionStates::STATE_REFUNDED;

                $this->doRefund($orderTransaction, $transitionState, $context);
                break;
            default:
                break;
        }
    }

    private function isAdyenPaymentMethod(string $paymentMethodId, Context $context)
    {
        if (!is_array($this->adyenPaymentMethodIds)) {
            $this->adyenPaymentMethodIds = $this->paymentMethodRepository->searchIds(
                (new Criteria())->addFilter(
                    new EqualsFilter(
                        'pluginId',
                        $this->adyenPluginProvider->getAdyenPluginId()
                    )
                ),
                $context
            )->getIds();
        }

        return in_array($paymentMethodId, $this->adyenPaymentMethodIds);
    }

    private function markAsProcessing(string $notificationId)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', true);
        $this->logger->debug("Payment notification {$notificationId} marked as processing.");
    }

    private function requeueNotification(string $notificationId)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->setNotificationSchedule($notificationId, new \DateTime());
        $this->logger->debug("Payment notification {$notificationId} requeued.");
    }

    private function markAsDone(string $notificationId)
    {
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->changeNotificationState($notificationId, 'done', true);
        $this->logger->debug("Payment notification {$notificationId} marked as done.");
    }

    private function doRefund(OrderTransactionEntity $orderTransaction, string $transitionState, Context $context)
    {
        try {
            if (OrderTransactionStates::STATE_PARTIALLY_REFUNDED === $transitionState) {
                $this->transactionStateHandler->refundPartially($orderTransaction->getId(), $context);
            } else {
                $this->transactionStateHandler->refund($orderTransaction->getId(), $context);
            }
        } catch (IllegalTransitionException $exception) {
            // set to paid, and then try again
            $this->logger->info(
                'Transaction ' . $orderTransaction->getId() . ' is '
                . $orderTransaction->getStateMachineState()->getTechnicalName() . ' and could not be set to '
                . $transitionState . ', setting to paid and then retrying.'
            );
            $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
            $this->doRefund($orderTransaction, $transitionState, $context);
        }
    }
}
