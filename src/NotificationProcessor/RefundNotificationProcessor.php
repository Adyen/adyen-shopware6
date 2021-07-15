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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\NotificationProcessor;

use Adyen\Shopware\Service\RefundService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

class RefundNotificationProcessor extends NotificationProcessor implements NotificationProcessorInterface, RefundServiceAwareInterface
{
    /**
     * @var RefundService
     */
    protected RefundService $refundService;

    public function process(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->getOrder();
        $notification = $this->getNotification();
        $orderTransaction = $order->getTransactions()->first();
        $state = $orderTransaction->getStateMachineState()->getTechnicalName();
        $logContext = [
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'eventCode' => NotificationEventCodes::REFUND,
            'originalState' => $state
        ];

        if ($notification->isSuccess()) {
            $refundedAmount = (int) $notification->getAmountValue();
            $transactionAmount = $this->currencyUtil->sanitize(
                $orderTransaction->getAmount()->getTotalPrice(),
                $order->getCurrency()->getIsoCode()
            );

            if ($refundedAmount > $transactionAmount) {
                throw new \Exception(sprintf(
                    'The refunded amount %s is greater than the transaction amount. %s',
                        $refundedAmount,
                        $transactionAmount
                    )
                );
            }

            $newState = $refundedAmount < $transactionAmount
                ? OrderTransactionStates::STATE_PARTIALLY_REFUNDED
                : OrderTransactionStates::STATE_REFUNDED;
            // Transactions in refunded state cannot be transitioned to any other state,
            // however refunded_partially can be transitioned to refunded.
            if (in_array($state, [$newState, OrderTransactionStates::STATE_REFUNDED])) {
                $this->logger->info("Transaction is already in the {$newState} state.", $logContext);
                return;
            }

            $this->refundService->handleRefundNotification($order, $notification);

            $this->doRefund($orderTransaction, $context, $newState);
            $logContext['newState'] = $newState;
        }

        $this->logger->info('Processed ' . NotificationEventCodes::REFUND . ' notification.', $logContext);
    }

    private function doRefund(OrderTransactionEntity $orderTransaction, Context $context, string $newState)
    {
        try {
            if ($newState === OrderTransactionStates::STATE_PARTIALLY_REFUNDED) {
                $this->getTransactionStateHandler()->refundPartially($orderTransaction->getId(), $context);
            } else {
                $this->getTransactionStateHandler()->refund($orderTransaction->getId(), $context);
            }
        } catch (IllegalTransitionException $exception) {
            // set to paid, and then try again
            $this->logger->info(
                'Transaction ' . $orderTransaction->getId() . ' is '
                . $orderTransaction->getStateMachineState()->getTechnicalName() . ' and could not be set to '
                . $newState . ', setting to paid and then retrying.'
            );
            $this->getTransactionStateHandler()->paid($orderTransaction->getId(), $context);
            $this->doRefund($orderTransaction, $context, $newState);
        }
    }

    /**
     * @param RefundService $refundService
     */
    public function setRefundService(RefundService $refundService): void
    {
        $this->refundService = $refundService;
    }

    /**
     * @return RefundService
     */
    public function getRefundService(): RefundService
    {
        return $this->refundService;
    }
}
