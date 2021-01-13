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

class OfferClosedNotificationProcessor extends NotificationProcessor implements NotificationProcessorInterface
{
    public function process(): void
    {
        $orderTransaction = $this->getOrder()->getTransactions()->first();
        $state = $orderTransaction->getStateMachineState()->getTechnicalName();
        $context = Context::createDefaultContext();
        $logContext = [
            'orderId' => $this->getOrder()->getId(),
            'orderNumber' => $this->getOrder()->getOrderNumber(),
            'eventCode' => NotificationEventCodes::OFFER_CLOSED,
            'originalState' => $state
        ];

        if ($this->getNotification()->isSuccess() && $state === OrderTransactionStates::STATE_IN_PROGRESS) {
            $this->getTransactionStateHandler()->fail($orderTransaction->getId(), $context);
            $logContext['newState'] = OrderTransactionStates::STATE_FAILED;
        }

        $this->logger->info('Processed ' . NotificationEventCodes::OFFER_CLOSED . ' notification.', $logContext);
    }
}
