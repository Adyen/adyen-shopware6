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

use Adyen\Shopware\AdyenPaymentShopware6;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\NotificationProcessor\NotificationProcessorFactory;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

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
     * @var PluginIdProvider
     */
    private $pluginIdProvider;
    /**
     * @var array|null
     */
    private $adyenPaymentMethodIds = null;

    /**
     * @var RefundService
     */
    private $refundService;

    private const MAX_ERROR_COUNT = 3;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $paymentMethodRepository,
        PluginIdProvider $pluginIdProvider,
        RefundService $refundService
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->pluginIdProvider = $pluginIdProvider;
        $this->refundService = $refundService;
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

            if (!$order) {
                $this->logger->warning("Order with order_number {$notification->getMerchantReference()} not found.");
                $this->markAsDone($notification->getId());
                continue;
            }

            $logContext = [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'eventCode' => $notification->getEventCode(),
            ];

            // Skip when the last payment method was non-Adyen.
            if (!$this->isAdyenPaymentMethod($order->getTransactions()->first()->getPaymentMethodId(), $context)) {
                $this->logger->info('Notification ignored: non-Adyen payment method last used, .', $logContext);
                $this->markAsDone($notification->getId());
                continue;
            }

            $notificationProcessor = NotificationProcessorFactory::create(
                $notification,
                $order,
                $this->transactionStateHandler,
                $this->logger,
                $this->refundService
            );

            try {
                $notificationProcessor->process();
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage());
                // set notification error and increment error count
                $errorCount = (int) $notification->getErrorCount();
                $this->notificationService->saveError($notification->getId(), $exception->getMessage(), ++$errorCount);
                $this->logger->error('Notification processing failed.', $logContext);

                if ($errorCount < self::MAX_ERROR_COUNT) {
                    $this->requeueNotification($notification->getId());
                }

                continue;
            }

            $this->markAsDone($notification->getId());
        }

        $this->logger->info('Processed ' . $notifications->count() . ' notifications.');
    }

    private function isAdyenPaymentMethod(string $paymentMethodId, Context $context)
    {
        if (!is_array($this->adyenPaymentMethodIds)) {
            $this->adyenPaymentMethodIds = $this->paymentMethodRepository->searchIds(
                (new Criteria())->addFilter(
                    new EqualsFilter(
                        'pluginId',
                        $this->pluginIdProvider->getPluginIdByBaseClass(
                            AdyenPaymentShopware6::class,
                            $context
                        )
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
}
