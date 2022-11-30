<?php
/**
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask\Webhook;

use Adyen\Webhook\PaymentStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

trait CancellableWebhookHandlerTrait
{
    protected function handleCancelWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        string $state,
        Context $context
    ): void {
        if (PaymentStates::STATE_CANCELLED === $state) {
            $orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);
        }
    }
}
