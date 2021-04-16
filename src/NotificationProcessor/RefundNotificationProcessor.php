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

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

class RefundNotificationProcessor extends NotificationProcessor implements NotificationProcessorInterface
{
    public function process(): void
    {
        $context = Context::createDefaultContext();
        $orderTransaction = $this->getOrder()->getTransactions()->first();
        $state = $orderTransaction->getStateMachineState()->getTechnicalName();
        $logContext = [
            'orderId' => $this->getOrder()->getId(),
            'orderNumber' => $this->getOrder()->getOrderNumber(),
            'eventCode' => NotificationEventCodes::REFUND,
            'originalState' => $state
        ];

        if ($this->getNotification()->isSuccess()) {
            $refundedAmount = (int) $this->getNotification()->getAmountValue();
            $transactionAmount = $this->currencyUtil->sanitize(
                $orderTransaction->getAmount()->getTotalPrice(),
                $this->getOrder()->getCurrency()->getIsoCode()
            );

            if ($refundedAmount > $transactionAmount) {
                $this->logger->warning('The refunded amount is greater than the transaction amount.', $logContext);
                return;
            }

            $partial = $refundedAmount < $transactionAmount;
            $newState = $partial
                ? OrderTransactionStates::STATE_PARTIALLY_REFUNDED
                : OrderTransactionStates::STATE_REFUNDED;
            // Transactions in refunded state cannot be transitioned to any other state,
            // however refunded_partially can be transitioned to refunded.
            if (in_array($state, [$newState, OrderTransactionStates::STATE_REFUNDED])) {
                $this->logger->info("Transaction is already in the {$newState} state.", $logContext);
                return;
            }

            $this->doRefund($orderTransaction, $context, $partial);
            $logContext['newState'] = $newState;
        }

        $this->logger->info('Processed ' . NotificationEventCodes::REFUND . ' notification.', $logContext);
    }

    private function doRefund(OrderTransactionEntity $orderTransaction, Context $context, bool $partial = false)
    {
        try {
            if ($partial) {
                $this->getTransactionStateHandler()->refundPartially($orderTransaction->getId(), $context);
            } else {
                $this->getTransactionStateHandler()->refund($orderTransaction->getId(), $context);
            }
        } catch (IllegalTransitionException $exception) {
            // set to paid, and then try again
            $this->getTransactionStateHandler()->paid($orderTransaction->getId(), $context);
            $this->doRefund($orderTransaction, $context, $partial);
        }
    }
}
