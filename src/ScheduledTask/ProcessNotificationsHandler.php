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
use Adyen\Shopware\NotificationProcessor\NotificationEvents;
use Adyen\Shopware\NotificationProcessor\NotificationProcessorFactory;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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

            $this->markAsProcessing($notification, $context);

            $order = $this->orderRepository->getWithOrderNumber(
                $notification->getMerchantReference(),
                $context
            );

            if (!$order) {
                $this->logger->warning("Order with order_number {$notification->getMerchantReference()} not found.");
                $this->markAsDone($notification, $context);
                continue;
            }

            // Skip when the last payment method was non-Adyen.
            if(!$this->isAdyenPaymentMethod($order->getTransactions()->first()->getPaymentMethodId(), $context)) {
                $this->markAsDone($notification, $context);
                continue;
            }

            $notificationProcessor = NotificationProcessorFactory::create($notification, $order, $this->transactionStateHandler);
            $result = $notificationProcessor->process();

            if ($result) {
                $this->markAsDone($notification, $context);
            }

            /*$lastTransaction = $order->getTransactions()->first();
            $state = $lastTransaction->getStateMachineState()->getTechnicalName();
            switch ($notification->getEventCode()) {
                case NotificationEvents::EVENT_AUTHORISATION:
                    // process
                    if ($notification->isSuccess()) {
                        if ($state !== OrderTransactionStates::STATE_PAID) {
                            $this->transactionStateHandler->paid($lastTransaction->getId(), $context);
                        }
                    } else {
                        if ($state == OrderTransactionStates::STATE_IN_PROGRESS) {
                            $this->transactionStateHandler->fail($lastTransaction->getId(), $context);
                        }
                    }
                    break;
                case NotificationEvents::EVENT_OFFER_CLOSED:
                    // process
                    if ($notification->isSuccess()) {
                        $this->transactionStateHandler->fail($lastTransaction->getId(), $context);
                    }
                    break;
                default:
                    // do nothing, log it
                    break;
            }*/
        }

        $this->logger->debug(ProcessNotifications::class . ' tasks are running.');
    }

    private function isAdyenPaymentMethod(string $paymentMethodId, Context $context)
    {
        $adyenPaymentMethodIds = $this->paymentMethodRepository->searchIds(
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

        return in_array($paymentMethodId, $adyenPaymentMethodIds);
    }

    private function markAsProcessing(NotificationEntity $notification, Context $context)
    {
        // mark as processing
        // log
    }

    private function markAsDone(NotificationEntity $notification, Context $context)
    {
        // mark as done
        // log
    }
}
