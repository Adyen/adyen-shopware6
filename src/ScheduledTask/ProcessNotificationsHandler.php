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

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        NotificationService $notificationService,
        OrderRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $paymentMethodRepository,
        PluginIdProvider $pluginIdProvider
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->pluginIdProvider = $pluginIdProvider;
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

            $this->markAsProcessing($notification->getId(), $context);

            $order = $this->orderRepository->getWithOrderNumber(
                $notification->getMerchantReference(),
                $context
            );

            if (!$order) {
                $this->logger->warning("Order with order_number {$notification->getMerchantReference()} not found.");
                $this->markAsDone($notification->getId(), $context);
                continue;
            }

            // Skip when the last payment method was non-Adyen.
            if (!$this->isAdyenPaymentMethod($order->getTransactions()->first()->getPaymentMethodId(), $context)) {
                $this->markAsDone($notification->getId(), $context);
                continue;
            }

            $notificationProcessor = NotificationProcessorFactory::create(
                $notification,
                $order,
                $this->transactionStateHandler
            );

            try {
                $notificationProcessor->process();
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage());
                // set notification error and error and error count
                // what to do with the notification state?

                continue;
            }

            $this->markAsDone($notification->getId(), $context);
        }

        $this->logger->debug(ProcessNotifications::class . ' tasks are running.');
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

    private function markAsProcessing(string $notificationId, Context $context)
    {
        // mark as processing
        $this->notificationService->changeNotificationState($notificationId, 'processing', true);
        // log
        $this->logger->debug("Payment notification {$notificationId} marked as processing.");
    }

    private function markAsDone(string $notificationId, Context $context)
    {
        // mark as done
        $this->notificationService->changeNotificationState($notificationId, 'processing', false);
        $this->notificationService->changeNotificationState($notificationId, 'done', true);
        // log
        $this->logger->debug("Payment notification {$notificationId} marked as done.");
    }
}
