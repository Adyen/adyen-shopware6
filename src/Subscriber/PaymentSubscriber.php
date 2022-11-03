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
use Adyen\Shopware\Handlers\OneClickPaymentMethodHandler;
use Adyen\Shopware\Provider\AdyenPluginProvider;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentMethodsFilterService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Util\Currency;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Cart\Exception\CartTokenNotFoundException;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextTokenChangeEvent;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

class PaymentSubscriber extends StorefrontSubscriber implements EventSubscriberInterface
{
    /**
     * @var PaymentStateDataService
     */
    private $paymentStateDataService;

    /**
     * @var PaymentMethodsFilterService
     */
    private $paymentMethodsFilterService;

    /**
     * @var RouterInterface
     */
    private $router;

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
     * @var EntityRepositoryInterface $paymentMethodRepository
     */
    private $paymentMethodRepository;

    /**
     * @var SessionInterface $session
     */
    private $session;

    /**
     * @var CartPersisterInterface
     */
    private $cartPersister;

    /**
     * @var CartCalculator
     */
    private $cartCalculator;

    /**
     * @var Currency
     */
    private $currency;

    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;

    /**
     * @var AbstractContextSwitchRoute
     */
    private $contextSwitchRoute;

    /**
     * @var AbstractSalesChannelContextFactory
     */
    private $salesChannelContextFactory;

    /**
     * PaymentSubscriber constructor.
     *
     * @param AdyenPluginProvider $adyenPluginProvider
     * @param PaymentMethodsFilterService $paymentMethodsFilterService
     * @param PaymentStateDataService $paymentStateDataService
     * @param RouterInterface $router
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param PaymentMethodsService $paymentMethodsService
     * @param EntityRepositoryInterface $paymentMethodRepository
     * @param SessionInterface $session
     * @param CartPersisterInterface $cartPersister
     * @param CartCalculator $cartCalculator
     * @param AbstractContextSwitchRoute $contextSwitchRoute
     * @param AbstractSalesChannelContextFactory $salesChannelContextFactory
     * @param Currency $currency
     */
    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        PaymentStateDataService $paymentStateDataService,
        RouterInterface $router,
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        PaymentMethodsService $paymentMethodsService,
        EntityRepositoryInterface $paymentMethodRepository,
        SessionInterface $session,
        CartPersisterInterface $cartPersister,
        CartCalculator $cartCalculator,
        AbstractContextSwitchRoute $contextSwitchRoute,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        Currency $currency
    ) {
        $this->paymentStateDataService = $paymentStateDataService;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->router = $router;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->session = $session;
        $this->cartPersister = $cartPersister;
        $this->cartCalculator = $cartCalculator;
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->currency = $currency;
        $this->adyenPluginProvider = $adyenPluginProvider;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onShoppingCartLoaded',
            OffcanvasCartPageLoadedEvent::class => 'onShoppingCartLoaded',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            RequestEvent::class => 'onKernelRequest',
        ];
    }

    private function getComponentData(SalesChannelContext $salesChannelContext): array
    {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        return [
            'clientKey' => $this->configurationService->getClientKey($salesChannelId),
            'locale' => $this->salesChannelRepository
                ->getSalesChannelAssoc($salesChannelContext, ['language.locale'])
                ->getLanguage()->getLocale()->getCode(),
            'environment' => $this->configurationService->getEnvironment($salesChannelId),
        ];
    }

    /**
     * @param PageLoadedEvent $event
     */
    public function onShoppingCartLoaded(PageLoadedEvent $event)
    {
        /** @var CheckoutCartPage|OffcanvasCartPage $page */
        $page = $event->getPage();
        if ($page->getCart()->getLineItems()->count() === 0) {
            return;
        }
        $salesChannelContext = $event->getSalesChannelContext();

        $shopwarePaymentMethods = null;
        if ($page instanceof CheckoutCartPage) {
            $shopwarePaymentMethods = $page->getPaymentMethods();
        }
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $currencySymbol = $salesChannelContext->getCurrency()->getSymbol();
        $amountInMinorUnits = $this->currency->sanitize($page->getCart()->getPrice()->getTotalPrice(), $currency);

        $paymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);
        $giftcards = $this->paymentMethodsFilterService->getAvailableGiftcards(
            $salesChannelContext,
            $paymentMethods,
            $this->adyenPluginProvider->getAdyenPluginId(),
            $shopwarePaymentMethods
        );
        $selectedPaymentMethodId = null;
        $giftcardData = $this->paymentStateDataService
            ->getPaymentStateDataFromContextToken($salesChannelContext->getToken());
        $giftcardDiscount = 0;
        $giftcardBalance = 0;
        if ($giftcardData) {
            $stateData = $giftcardData->getStateData();
            $giftcardDiscount = json_decode($stateData, true)['additionalData']['amount'] ?? 0;
            $selectedPaymentMethodId = json_decode($stateData, true)['additionalData']['paymentMethodId'] ?? 0;
            $giftcardBalance = json_decode($stateData, true)['additionalData']['balance'] ?? 0;

            // update discount amount if total becomes less than discount
            if ((int) $giftcardDiscount > $amountInMinorUnits) {
                $newBalance = ($giftcardDiscount - $amountInMinorUnits) + $giftcardBalance;
                $this->paymentStateDataService->insertPaymentStateData(
                    $salesChannelContext->getToken(),
                    $stateData,
                    [
                        'amount' => $amountInMinorUnits,
                        'paymentMethodId' => $selectedPaymentMethodId,
                        'balance' => $newBalance,
                    ]
                );
                $giftcardDiscount = $amountInMinorUnits;
                $this->contextSwitchRoute->switchContext(
                    new RequestDataBag(
                        [
                            SalesChannelContextService::PAYMENT_METHOD_ID => $selectedPaymentMethodId
                        ]
                    ),
                    $salesChannelContext
                );
            }
        }

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                array_merge($this->getComponentData($salesChannelContext), [
                    'giftcards' => $giftcards->getElements(),
                    'totalPrice' => $page->getCart()->getPrice()->getTotalPrice(),
                    'totalInMinorUnits' => $amountInMinorUnits,
                    'currency' => $currency,
                    'currencySymbol' => $currencySymbol,
                    'giftcardDiscount' => $giftcardDiscount,
                    'giftcardBalance' => $giftcardBalance,
                    'checkBalanceUrl' => $this->router
                        ->generate('store-api.action.adyen.payment-methods.balance'),
                    'setGiftcardUrl' => $this->router->generate('store-api.action.adyen.giftcard'),
                    'removeGiftcardUrl' => $this->router->generate('store-api.action.adyen.giftcard.remove'),
                    'switchContextUrl' => $this->router->generate('store-api.switch-context'),
                    'shoppingCartPageUrl' => $this->router->generate('frontend.checkout.cart.page'),
                ])
            )
        );
    }

    /**
     * Adds vars to frontend template to be used in JS
     *
     * @param PageLoadedEvent $event
     */
    public function onCheckoutConfirmLoaded(PageLoadedEvent $event)
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $selectedPaymentMethod = $salesChannelContext->getPaymentMethod();
        $page = $event->getPage();
        $orderId = '';
        if (method_exists($page, 'getOrder')) {
            $orderId = $page->getOrder()->getId();
        }
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $totalPrice = 0;
        try {
            $cart = $this->cartCalculator->calculate(
                $this->cartPersister->load($salesChannelContext->getToken(), $salesChannelContext),
                $salesChannelContext
            );
            $totalPrice = $cart->getPrice()->getTotalPrice();
        } catch (CartTokenNotFoundException $exception) {
            $cart = null;
            if (!empty($orderId)) {
                $totalPrice = $page->getOrder()->getPrice()->getTotalPrice();
            }
        }
        $amount = $this->currency->sanitize($totalPrice, $currency);

        $adyenPluginId = $this->adyenPluginProvider->getAdyenPluginId();
        $displaySaveCreditCardOption = $this->paymentMethodsFilterService->isPaymentMethodInCollection(
            $page->getPaymentMethods(),
            OneClickPaymentMethodHandler::getPaymentMethodCode(),
            $adyenPluginId,
        );
        $paymentMethodsResponse = $this->paymentMethodsService->getPaymentMethods($salesChannelContext, $orderId);
        $giftcardData = $this->paymentStateDataService
            ->getPaymentStateDataFromContextToken($salesChannelContext->getToken());
        $giftcardDiscount = 0;
        $payInFullWithGiftcard = false;
        $adyenGiftcardSelected = ($selectedPaymentMethod->getPluginId() === $adyenPluginId)
            && $selectedPaymentMethod->getHandlerIdentifier()::$isGiftCard;
        if ($giftcardData) {
            $stateData = $giftcardData->getStateData();
            $giftcardDiscount = json_decode($stateData, true)['additionalData']['amount'] ?? 0;
            if ($giftcardDiscount >= $amount) {
                $payInFullWithGiftcard = true;
            }
        }
        $filteredPaymentMethods = $this->paymentMethodsFilterService->filterShopwarePaymentMethods(
            $page->getPaymentMethods(),
            $salesChannelContext,
            $adyenPluginId,
            $paymentMethodsResponse,
            $payInFullWithGiftcard
        );

        if (!$payInFullWithGiftcard && $adyenGiftcardSelected) {
            $selectedPaymentMethod = $filteredPaymentMethods->first();
            $this->contextSwitchRoute->switchContext(
                new RequestDataBag(
                    [
                        SalesChannelContextService::PAYMENT_METHOD_ID => $selectedPaymentMethod->getId()
                    ]
                ),
                $salesChannelContext
            );
            $adyenGiftcardSelected = false;
        }

        $currencySymbol = $salesChannelContext->getCurrency()->getSymbol();

        $page->setPaymentMethods($filteredPaymentMethods);

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                array_merge(
                    $this->getComponentData($salesChannelContext),
                    [
                        'paymentStatusUrl' => $this->router->generate('store-api.action.adyen.payment-status'),
                        'createOrderUrl' => $this->router->generate('store-api.action.adyen.orders'),
                        'checkoutOrderUrl' => $this->router->generate('store-api.checkout.cart.order'),
                        'paymentHandleUrl' => $this->router->generate('store-api.payment.handle'),
                        'paymentDetailsUrl' => $this->router->generate('store-api.action.adyen.payment-details'),
                        'paymentFinishUrl' => $this->router->generate(
                            'frontend.checkout.finish.page',
                            ['orderId' => '']
                        ),
                        'paymentErrorUrl' => $this->router->generate(
                            'frontend.checkout.finish.page',
                            [
                                'orderId' => '',
                                'changedPayment' => false,
                                'paymentFailed' => true,
                            ]
                        ),
                        'updatePaymentUrl' => $this->router->generate(
                            'store-api.action.adyen.set-payment'
                        ),
                        'cancelOrderTransactionUrl' => $this->router->generate(
                            'store-api.action.adyen.cancel-order-transaction',
                        ),
                        'languageId' => $salesChannelContext->getContext()->getLanguageId(),
                        'currency' => $currency,
                        'amount' => $amount,
                        'paymentMethodsResponse' => json_encode($paymentMethodsResponse),
                        'orderId' => $orderId,
                        'pluginId' => $this->adyenPluginProvider->getAdyenPluginId(),
                        'totalPrice' => $totalPrice,
                        'giftcardDiscount' => $giftcardDiscount,
                        'currencySymbol' => $currencySymbol,
                        'payInFullWithGiftcard' => (int) $payInFullWithGiftcard,
                        'adyenGiftcardSelected' => (int) $adyenGiftcardSelected,
                        'storedPaymentMethods' => $paymentMethodsResponse['storedPaymentMethods'] ?? [],
                        'selectedPaymentMethodHandler' => $selectedPaymentMethod->getFormattedHandlerIdentifier(),
                        'selectedPaymentMethodPluginId' => $selectedPaymentMethod->getPluginId(),
                        'displaySaveCreditCardOption' => $displaySaveCreditCardOption,
                        'billingAddressStreetHouse' => $this->paymentMethodsService->getSplitStreetAddressHouseNumber(
                            $salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet()
                        ),
                        'shippingAddressStreetHouse' => $this->paymentMethodsService->getSplitStreetAddressHouseNumber(
                            $salesChannelContext->getCustomer()->getActiveShippingAddress()->getStreet()
                        ),
                    ]
                )
            )
        );
    }

    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();
        if (($request->attributes->get('_route') === 'frontend.account.edit-order.change-payment-method')
            && $request->request->has('adyenStateData')) {
            $this->contextSwitchRoute->switchContext(
                new RequestDataBag(
                    [
                        SalesChannelContextService::PAYMENT_METHOD_ID => $request->get('paymentMethodId'),
                        'adyenStateData' => $request->request->get('adyenStateData'),
                        'adyenOrigin' => $request->request->get('adyenOrigin'),
                    ]
                ),
                $this->salesChannelContextFactory->create(
                    $this->session->get('sw-context-token'),
                    $request->attributes->get('sw-sales-channel-id')
                )
            );
            $event->setResponse(
                new RedirectResponse(
                    $this->router->generate(
                        'frontend.account.edit-order.page',
                        ['orderId' => $request->attributes->get('orderId')]
                    )
                )
            );
        }
    }
}
