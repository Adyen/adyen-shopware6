<?php declare(strict_types=1);
/**
 * Adyen Payment Module
 *
 * Copyright (c) 2026 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask\Webhook;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

/**
 * Moves the transaction to `authorized` for payments that wait for a capture.
 *
 * A capture-on-shipment notification is processed again on every retry. Shopware allows the `authorize` transition
 * only from the states below, so calling it again on an `authorized` or `paid` transaction throws and the capture is
 * never sent (spec §8.1, decision Q6).
 */
trait AuthorizableWebhookHandlerTrait
{
    /**
     * @return list<string> States from which Shopware allows the `authorize` transition.
     */
    private static function getAuthorizableStates(): array
    {
        return [
            OrderTransactionStates::STATE_OPEN,
            OrderTransactionStates::STATE_IN_PROGRESS,
            OrderTransactionStates::STATE_REMINDED,
            OrderTransactionStates::STATE_UNCONFIRMED,
        ];
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param LoggerInterface $logger
     * @param Context $context
     *
     * @return bool True if the transaction is authorised now and the capture flow continues; false if it is in
     *     another state (for example `paid` after a manual capture, or `cancelled`) and there is nothing to do.
     */
    protected function authorizeForCapture(
        OrderTransactionEntity $orderTransaction,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        LoggerInterface $logger,
        Context $context
    ): bool {
        $stateMachineState = $orderTransaction->getStateMachineState();
        $state = $stateMachineState !== null ? $stateMachineState->getTechnicalName() : null;

        if ($state === OrderTransactionStates::STATE_AUTHORIZED) {
            return true;
        }

        // An unknown state keeps the previous behavior: try to authorise.
        if ($state === null || in_array($state, self::getAuthorizableStates(), true)) {
            $logger->info(
                'Manual capture required. Setting payment to `authorised` state.',
                ['orderTransactionId' => $orderTransaction->getId()]
            );
            $orderTransactionStateHandler->authorize($orderTransaction->getId(), $context);

            return true;
        }

        $logger->info(
            'Transaction is not waiting for a capture any more, nothing to do.',
            ['orderTransactionId' => $orderTransaction->getId(), 'state' => $state]
        );

        return false;
    }
}
