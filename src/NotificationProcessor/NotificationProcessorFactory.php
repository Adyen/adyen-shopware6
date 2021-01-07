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

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;

class NotificationProcessorFactory
{
    private static $adyenEventCodeProcessors = [
        NotificationEvents::EVENT_AUTHORISATION => AuthorisationProcessor::class,
        NotificationEvents::EVENT_OFFER_CLOSED => OfferClosedProcessor::class,
    ];

    public static function create(
        NotificationEntity $notification,
        OrderEntity $order,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        /** @var BaseProcessor $notificationProcessor */
        $notificationProcessor = array_key_exists($notification->getEventCode(), self::$adyenEventCodeProcessors)
            ? new self::$adyenEventCodeProcessors[$notification->getEventCode()]()
            : new BaseProcessor();

        $notificationProcessor->setOrder($order);
        $notificationProcessor->setNotification($notification);
        $notificationProcessor->setTransactionStateHandler($transactionStateHandler);

        return $notificationProcessor;
    }
}
