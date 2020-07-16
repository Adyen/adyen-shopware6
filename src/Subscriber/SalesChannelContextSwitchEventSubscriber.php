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

namespace Adyen\Shopware\Subscriber;

use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Adyen\Shopware\Service\PaymentStateDataService;

class SalesChannelContextSwitchEventSubscriber implements EventSubscriberInterface
{

    private $paymentStateDataService;

    function __construct(PaymentStateDataService $paymentStateDataService)
    {
        $this->paymentStateDataService = $paymentStateDataService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            \Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent::class
            => 'onContextTokenUpdate'
        ];
    }

    public function onContextTokenUpdate(SalesChannelContextSwitchEvent $event)
    {
        if ($event->getRequestDataBag()->get('stateData')) {
            $this->paymentStateDataService->insertPaymentStateData(
                $event->getSalesChannelContext()->getToken(),
                $event->getRequestDataBag()->get('stateData')
            );
        }
    }
}
