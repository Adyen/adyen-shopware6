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

namespace Adyen\Shopware\Subscriber;

use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Struct\AdyenContextDataStruct;
use Shopware\Core\Framework\Routing\Event\SalesChannelContextResolvedEvent;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextTokenChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ContextSubscriber implements EventSubscriberInterface
{
    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var PaymentStateDataService
     */
    private $paymentStateDataService;

    public function __construct(
        ConfigurationService $configurationService,
        PaymentStateDataService $paymentStateDataService
    ) {
        $this->configurationService = $configurationService;
        $this->paymentStateDataService = $paymentStateDataService;
    }

    public static function getSubscribedEvents()
    {
        return [
            SalesChannelContextResolvedEvent::class => 'addAdyenData',
            SalesChannelContextTokenChangeEvent::class => 'onContextTokenChange'
        ];
    }

    public function onContextTokenChange(SalesChannelContextTokenChangeEvent $event)
    {
        $token = $event->getCurrentToken();
        $oldToken = $event->getPreviousToken();

        $stateData = $this->paymentStateDataService->getPaymentStateDataFromContextToken($oldToken);

        if ($stateData) {
            $this->paymentStateDataService->updateStateDataContextToken($stateData, $token);
        }
    }

    public function addAdyenData(SalesChannelContextResolvedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $salesChannelId = $context->getSalesChannelId();

        $extension = new AdyenContextDataStruct();
        $context->addExtension('adyenData', $extension);

        $extension->setClientKey($this->configurationService->getClientKey($salesChannelId) ?: null);
        $extension->setEnvironment($this->configurationService->getEnvironment($salesChannelId));

        $data = $this->paymentStateDataService->getPaymentStateDataFromContextToken($context->getToken());
        $extension->setHasPaymentStateData(!empty($data));
    }
}
