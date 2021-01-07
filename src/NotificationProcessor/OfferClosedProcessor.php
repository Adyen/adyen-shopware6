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

class OfferClosedProcessor extends BaseProcessor
{
    public function process()
    {
        $orderTransaction = $this->getOrder()->getTransactions()->first();
        $state = $orderTransaction->getStateMachineState()->getTechnicalName();
        $context = Context::createDefaultContext();

        if ($this->getNotification()->isSuccess() && $state === OrderTransactionStates::STATE_IN_PROGRESS) {
            $this->getTransactionStateHandler()->fail($orderTransaction->getId(), $context);
        }
    }
}
