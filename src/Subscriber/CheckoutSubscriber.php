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

use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Adyen\Shopware\Service\PaymentStateDataService;

class CheckoutSubscriber implements EventSubscriberInterface
{

    const ADYEN_DATA_EXTENSION_ID = 'adyenFrontendData';

    /**
     * @var PaymentStateDataService
     */
    private $paymentStateDataService;

    /**
     * CheckoutSubscriber constructor.
     * @param PaymentStateDataService $paymentStateDataService
     */
    public function __construct(
        PaymentStateDataService $paymentStateDataService
    ) {
        $this->paymentStateDataService = $paymentStateDataService;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextSwitchEvent::class => 'onContextTokenUpdate',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded'
        ];
    }

    /**
     * Persists the Adyen payment state data on payment method confirmation/update
     *
     * @param SalesChannelContextSwitchEvent $event
     */
    public function onContextTokenUpdate(SalesChannelContextSwitchEvent $event)
    {
        if ($event->getRequestDataBag()->get('adyenStateData')) {
            $this->paymentStateDataService->insertPaymentStateData(
                $event->getSalesChannelContext()->getToken(),
                $event->getRequestDataBag()->get('adyenStateData'),
                $event->getRequestDataBag()->get('adyenOrigin')
            );
        }
    }

    /**
     * Adds vars to frontend template to be used in JS
     *
     * @param CheckoutConfirmPageLoadedEvent $event
     */
    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event)
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $page = $event->getPage();
        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                [
                    'languageId' => $salesChannelContext->getContext()->getLanguageId()
                ]
            )
        );
    }
}
