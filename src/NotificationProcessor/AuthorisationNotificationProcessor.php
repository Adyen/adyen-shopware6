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

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class AuthorisationNotificationProcessor extends NotificationProcessor implements NotificationProcessorInterface
{
    public function process(): void
    {
        $orderTransaction = $this->getOrder()->getTransactions()->first();
        $state = $orderTransaction->getStateMachineState()->getTechnicalName();
        $context = Context::createDefaultContext();

        $paymentMethod = $orderTransaction->getPaymentMethod();
        if ($this->getNotification()->isSuccess()) {
            if ($state !== OrderTransactionStates::STATE_PAID) {
                $this->getTransactionStateHandler()->paid($orderTransaction->getId(), $context);
            }
        } else {
            if ($state === OrderTransactionStates::STATE_IN_PROGRESS) {
                $this->getTransactionStateHandler()->fail($orderTransaction->getId(), $context);
            }
        }
    }
}
