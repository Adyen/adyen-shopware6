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

use Adyen\Shopware\Entity\PaymentStateData\PaymentStateDataEntity;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Struct\AdyenContextDataStruct;
use Adyen\Util\Currency;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\Exception\CartTokenNotFoundException;
use Shopware\Core\Framework\Routing\Event\SalesChannelContextResolvedEvent;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextRestoredEvent;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextTokenChangeEvent;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ContextSubscriber implements EventSubscriberInterface
{
    private ConfigurationService $configurationService;
    private PaymentStateDataService $paymentStateDataService;
    private AbstractContextSwitchRoute $contextSwitchRoute;
    private AbstractCartPersister $cartPersister;
    private CartCalculator $cartCalculator;
    private Currency $currency;

    public function __construct(
        ConfigurationService $configurationService,
        PaymentStateDataService $paymentStateDataService,
        AbstractContextSwitchRoute $contextSwitchRoute,
        AbstractCartPersister $cartPersister,
        CartCalculator $cartCalculator,
        Currency $currency
    ) {
        $this->configurationService = $configurationService;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->cartPersister = $cartPersister;
        $this->cartCalculator = $cartCalculator;
        $this->currency = $currency;
    }

    public static function getSubscribedEvents()
    {
        return [
            SalesChannelContextResolvedEvent::class => 'addAdyenData',
            SalesChannelContextTokenChangeEvent::class => 'onContextTokenChange',
            SalesChannelContextRestoredEvent::class => 'onContextRestored'
        ];
    }

    public function onContextRestored(SalesChannelContextRestoredEvent $event): void
    {
        $token = $event->getRestoredSalesChannelContext()->getToken();
        $oldToken = $event->getCurrentSalesChannelContext()->getToken();

        $stateData = $this->paymentStateDataService->getPaymentStateDataFromContextToken($oldToken);

        if ($stateData) {
            $this->paymentStateDataService->updateStateDataContextToken($stateData, $token);
            $this->setGiftcardPaymentMethodAfterContextRestored($event->getRestoredSalesChannelContext(), $stateData);
        }
    }

    public function onContextTokenChange(SalesChannelContextTokenChangeEvent $event): void
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

    private function setGiftcardPaymentMethodAfterContextRestored(
        SalesChannelContext $context,
        PaymentStateDataEntity $stateData,
    ): void {
        $currency = $context->getCurrency()->getIsoCode();
        $decodedStateData = json_decode($stateData->getStateData(), true);

        try {
            $cart = $this->cartCalculator->calculate(
                $this->cartPersister->load($context->getToken(), $context),
                $context
            );
            $totalPrice = $cart->getPrice()->getTotalPrice();
        } catch (CartTokenNotFoundException $exception) {
            // No cart information found.
            return;
        }

        $amount = $this->currency->sanitize($totalPrice, $currency);
        $giftcardDiscount = $decodedStateData['additionalData']['amount'] ?? 0;

        if ($giftcardDiscount >= $amount) {
            $this->contextSwitchRoute->switchContext(
                new RequestDataBag(
                    [
                        SalesChannelContextService::PAYMENT_METHOD_ID =>
                            $decodedStateData['additionalData']['paymentMethodId']
                    ]
                ),
                $context
            );
        }
    }
}
