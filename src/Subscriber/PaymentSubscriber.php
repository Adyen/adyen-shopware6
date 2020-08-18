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

use Adyen\AdyenException;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\OriginKeyService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoader;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Adyen\Shopware\Service\PaymentStateDataService;
use Symfony\Component\Routing\RouterInterface;

class PaymentSubscriber implements EventSubscriberInterface
{

    const ADYEN_DATA_EXTENSION_ID = 'adyenFrontendData';

    /**
     * @var PaymentStateDataService
     */
    private $paymentStateDataService;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var OriginKeyService
     */
    private $originKeyService;

    /**
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * PaymentSubscriber constructor.
     * @param PaymentStateDataService $paymentStateDataService
     * @param RouterInterface $router
     * @param OriginKeyService $originKeyService
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param PaymentMethodsService $paymentMethodsService
     */
    public function __construct(
        PaymentStateDataService $paymentStateDataService,
        RouterInterface $router,
        OriginKeyService $originKeyService,
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        PaymentMethodsService $paymentMethodsService
    ) {
        $this->paymentStateDataService = $paymentStateDataService;
        $this->router = $router;
        $this->originKeyService = $originKeyService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->paymentMethodsService = $paymentMethodsService;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextSwitchEvent::class => 'onContextTokenUpdate',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onCheckoutConfirmLoaded'
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
            try {
                $this->paymentStateDataService->insertPaymentStateData(
                    $event->getSalesChannelContext()->getToken(),
                    $event->getRequestDataBag()->get('adyenStateData'),
                    $event->getRequestDataBag()->get('adyenOrigin')
                );
            } catch (AdyenException $exception) {
                //TODO handle error
            }
        }
    }

    /**
     * Adds vars to frontend template to be used in JS
     *
     * @param PageLoadedEvent $event
     */
    public function onCheckoutConfirmLoaded(PageLoadedEvent $event)
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $page = $event->getPage();
        $orderId = '';
        if (method_exists($page, 'getOrder')) {
            $orderId = $page->getOrder()->getId();
        }

        $filteredPaymentMethods = $this->filterShopwarePaymentMethods(
            $page->getPaymentMethods(),
            $salesChannelContext
        );

        $page->setPaymentMethods($filteredPaymentMethods);

        $stateDataPaymentMethod = $this->paymentStateDataService->getPaymentMethodType(
            $salesChannelContext->getToken()
        );

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                [
                    'paymentStatusUrl' => $this->router->generate(
                        'sales-channel-api.action.adyen.payment-status',
                        ['version' => 2]
                    ),
                    'checkoutOrderUrl' => $this->router->generate(
                        'sales-channel-api.checkout.order.create',
                        ['version' => 2]
                    ),
                    'paymentDetailsUrl' => $this->router->generate(
                        'sales-channel-api.action.adyen.payment-details',
                        ['version' => 2]
                    ),
                    'paymentFinishUrl' => $this->router->generate(
                        'frontend.checkout.finish.page',
                        ['orderId' => '']
                    ),
                    'paymentErrorUrl' => $this->router->generate('frontend.checkout.finish.page', [
                        'orderId' => '',
                        'changedPayment' => false,
                        'paymentFailed' => true,
                    ]),
                    'editPaymentUrl' => $this->router->generate(
                        'store-api.order.set-payment',
                        ['version' => 2]
                    ),
                    'languageId' => $salesChannelContext->getContext()->getLanguageId(),
                    'originKey' => $this->originKeyService->getOriginKeyForOrigin(
                        $this->salesChannelRepository->getSalesChannelUrl($salesChannelContext)
                    )->getOriginKey(),
                    'locale' => $this->salesChannelRepository->getSalesChannelAssocLocale($salesChannelContext)
                        ->getLanguage()->getLocale()->getCode(),

                    'environment' => $this->configurationService->getEnvironment(),
                    'paymentMethodsResponse' => json_encode(
                        $this->paymentMethodsService->getPaymentMethods($salesChannelContext)
                    ),
                    'orderId' => $orderId,
                    'stateDataPaymentMethod' => $stateDataPaymentMethod
                ]
            )
        );
    }

    /**
     * Removes payment methods from the Shopware list if not present in Adyen's /paymentMethods response
     *
     * @param $originalPaymentMethods
     * @param SalesChannelContext $salesChannelContext
     * @return mixed
     */
    private function filterShopwarePaymentMethods($originalPaymentMethods, SalesChannelContext $salesChannelContext)
    {
        //TODO do this in an event instead
        //Adyen /paymentMethods response
        $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);

        foreach ($originalPaymentMethods as $paymentMethodEntity) {
            //TODO filter out unsupported PMs
            $pmHandlerIdentifier = $paymentMethodEntity->getHandlerIdentifier();

            //If this is an Adyen PM installed it will only be enabled if it's present in the /paymentMethods response
            if (strpos($paymentMethodEntity->getFormattedHandlerIdentifier(), 'adyen') !== false) {
                $pmCode = 'scheme'; //TODO get from payment method handler instead of hardcoding PM type
                // In case the paymentMethods response has no payment methods, remove it from the list
                if (empty($adyenPaymentMethods)) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    continue;
                }

                $pmFound = array_filter(
                    $adyenPaymentMethods['paymentMethods'],
                    function ($value) use ($pmCode) {
                        return $value['type'] == $pmCode;
                    }
                );

                if (empty($pmFound)) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                }
            }
        }
        return $originalPaymentMethods;
    }
}
