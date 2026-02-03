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

use Adyen\Shopware\Handlers\OneClickPaymentMethodHandler;
use Adyen\Shopware\Provider\AdyenPluginProvider;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\ExpressCheckoutService;
use Adyen\Shopware\Service\PaymentMethodsFilterService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Util\Currency;
use Adyen\Shopware\Util\RatePayDeviceFingerprintParamsProvider;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\Exception\CartTokenNotFoundException;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\AffiliateTracking\AffiliateTrackingListener;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

class PaymentSubscriber extends StorefrontSubscriber implements EventSubscriberInterface
{
    /**
     * @var PaymentStateDataService
     */
    private PaymentStateDataService $paymentStateDataService;

    /**
     * @var PaymentMethodsFilterService
     */
    private PaymentMethodsFilterService $paymentMethodsFilterService;

    /**
     * @var RouterInterface $router
     */
    private RouterInterface $router;

    /**
     * @var SalesChannelRepository $salesChannelRepository
     */
    private SalesChannelRepository $salesChannelRepository;

    /**
     * @var ConfigurationService $configurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var PaymentMethodsService $paymentMethodsService
     */
    private PaymentMethodsService $paymentMethodsService;

    /**
     * @var ExpressCheckoutService $expressCheckoutService
     */
    private ExpressCheckoutService $expressCheckoutService;

    /**
     * @var RequestStack $requestStack
     */
    private RequestStack $requestStack;

    /**
     * @var AbstractCartPersister $cartPersister
     */
    private AbstractCartPersister $cartPersister;

    /**
     * @var CartCalculator $cartCalculator
     */
    private CartCalculator $cartCalculator;

    /**
     * @var Currency $currency
     */
    private Currency $currency;

    /**
     * @var AdyenPluginProvider $adyenPluginProvider
     */
    private AdyenPluginProvider $adyenPluginProvider;

    /**
     * @var RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider
     */
    private RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider;

    /**
     * @var AbstractContextSwitchRoute $contextSwitchRoute
     */
    private AbstractContextSwitchRoute $contextSwitchRoute;

    /**
     * @var AbstractSalesChannelContextFactory $salesChannelContextFactory
     */
    private AbstractSalesChannelContextFactory $salesChannelContextFactory;

    /**
     * @var EntityRepository $paymentMethodRepository
     */
    private EntityRepository $paymentMethodRepository;

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
     * @param ExpressCheckoutService $expressCheckoutService
     * @param RequestStack $requestStack
     * @param AbstractCartPersister $cartPersister
     * @param CartCalculator $cartCalculator
     * @param AbstractContextSwitchRoute $contextSwitchRoute
     * @param AbstractSalesChannelContextFactory $salesChannelContextFactory
     * @param Currency $currency
     * @param RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider
     * @param EntityRepository $paymentMethodRepository
     */
    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        PaymentStateDataService $paymentStateDataService,
        RouterInterface $router,
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        PaymentMethodsService $paymentMethodsService,
        ExpressCheckoutService $expressCheckoutService,
        RequestStack $requestStack,
        AbstractCartPersister $cartPersister,
        CartCalculator $cartCalculator,
        AbstractContextSwitchRoute $contextSwitchRoute,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        Currency $currency,
        RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider,
        EntityRepository $paymentMethodRepository
    ) {
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->router = $router;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->expressCheckoutService = $expressCheckoutService;
        $this->requestStack = $requestStack;
        $this->cartPersister = $cartPersister;
        $this->cartCalculator = $cartCalculator;
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->currency = $currency;
        $this->ratePayFingerprintParamsProvider = $ratePayFingerprintParamsProvider;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onShoppingCartLoaded',
            OffcanvasCartPageLoadedEvent::class => 'onShoppingCartLoaded',
            CheckoutRegisterPageLoadedEvent::class => 'onShoppingCartLoaded',
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            RequestEvent::class => 'onKernelRequest',
        ];
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    private function getComponentData(SalesChannelContext $salesChannelContext): array
    {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        return [
            'clientKey' => $this->configurationService->getClientKey($salesChannelId),
            'locale' => $this->salesChannelRepository->getSalesChannelLocale($salesChannelContext),
            'environment' => $this->configurationService->getEnvironment($salesChannelId),
            'merchantAccount' => $this->configurationService->getMerchantAccount($salesChannelId)
        ];
    }

    /**
     * @param PageLoadedEvent $event
     */
    public function onShoppingCartLoaded(PageLoadedEvent $event): void
    {
        /** @var CheckoutCartPage|OffcanvasCartPage $page */
        $page = $event->getPage();
        if ($page->getCart()->getLineItems()->count() === 0) {
            return;
        }
        $salesChannelContext = $event->getSalesChannelContext();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $currencySymbol = $salesChannelContext->getCurrency()->getSymbol();
        $amountInMinorUnits = $this->currency->sanitize($page->getCart()->getPrice()->getTotalPrice(), $currency);

        $userLoggedIn = $salesChannelContext->getCustomer() && !$salesChannelContext->getCustomer()->getGuest();

        $affiliateCode = $this->requestStack->getSession()->get(AffiliateTrackingListener::AFFILIATE_CODE_KEY);
        $campaignCode = $this->requestStack->getSession()->get(AffiliateTrackingListener::CAMPAIGN_CODE_KEY);

        $this->requestStack->getSession()->set('adyenSwContextToken', $salesChannelContext->getToken());

        //Filter Payment Methods
        $shopwarePaymentMethods = null;
        if ($page instanceof CheckoutCartPage) {
            $shopwarePaymentMethods = $page->getPaymentMethods();
        }
        $paymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);
        $paymentMethodId = $this->paymentMethodsFilterService->getGiftCardPaymentMethodId($salesChannelContext);
        $criteria = (new Criteria())->addFilter(new EqualsFilter(
            'id',
            $paymentMethodId
        ));
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $salesChannelContext->getContext())->first();
        $giftcards = [];
        if ($paymentMethod && $paymentMethod->getActive() &&
            in_array(
                $paymentMethodId,
                $event->getSalesChannelContext()->getSalesChannel()->getPaymentMethodIds() ?? [],
                true
            )
        ) {
            $giftcards = $this->paymentMethodsFilterService->filterAdyenPaymentMethodsByType(
                $paymentMethods->getPaymentMethods() ?? [],
                'giftcard'
            );
        }

        //Remove giftcards from the Payment Method lists, as this lists gets populated at shipping details on cart page.
        $this->paymentMethodsFilterService->getAvailableNonGiftcardsPaymentMethods(
            $salesChannelContext,
            $shopwarePaymentMethods
        );

        $giftcardDetails = $this->paymentStateDataService->getGiftcardTotalDiscountAndBalance(
            $salesChannelContext,
            $page->getCart()->getPrice()->getTotalPrice()
        );

        $expressCheckoutConfigurationAvailable = true;
        $expressCheckoutConfiguration = [];
        $googlePayAvailable = $this->configurationService->isGooglePayExpressCheckoutEnabled();
        $payPalAvailable = $this->configurationService->isPayPalExpressCheckoutEnabled();
        $applePayAvailable = $this->configurationService->isApplePayExpressCheckoutEnabled();

        // If express checkout feature is disabled, returns empty payment method response
        if (!$googlePayAvailable && !$payPalAvailable && !$applePayAvailable) {
            $expressCheckoutConfigurationAvailable = false;
        }

        if ($expressCheckoutConfigurationAvailable) {
            $expressCheckoutConfiguration = $this->expressCheckoutService->getExpressCheckoutConfig(
                '-1',
                -1,
                $salesChannelContext
            );
            if (array_key_exists('error', $expressCheckoutConfiguration)) {
                $expressCheckoutConfigurationAvailable = false;
                $expressCheckoutConfiguration = [];
            }
        }

        // check if register page is loaded
        if ($event instanceof CheckoutRegisterPageLoadedEvent) {
            $showVouchersCheckout = $this->configurationService->getShowVouchersCheckout();
        }

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                array_merge(
                    $this->getComponentData($salesChannelContext),
                    [
                        'paymentStatusUrl' => $this->router->generate('payment.adyen.proxy-payment-status'),
                        'giftcards' => $giftcards,
                        'totalPrice' => $page->getCart()->getPrice()->getTotalPrice(),
                        'totalInMinorUnits' => $amountInMinorUnits,
                        'currency' => $currency,
                        'currencySymbol' => $currencySymbol,
                        'giftcardDiscount' => $giftcardDetails['giftcardDiscount'],
                        'giftcardBalance' => $giftcardDetails['giftcardBalance'],
                        'checkBalanceUrl' => $this->router
                            ->generate('payment.adyen.proxy-check-balance'),
                        'setGiftcardUrl' => $this->router->generate('payment.adyen.proxy-store-giftcard-state-data'),
                        'removeGiftcardUrl' => $this->router
                            ->generate('payment.adyen.proxy-remove-giftcard-state-data'),
                        'shoppingCartPageUrl' => $this->router->generate('frontend.checkout.cart.page'),
                        'fetchRedeemedGiftcardsUrl' => $this->router
                            ->generate('payment.adyen.proxy-fetch-redeemed-giftcards'),
                        'expressCheckoutConfigUrl' =>
                            $this->router->generate('payment.adyen.proxy-express-checkout-config'),
                        'checkoutOrderUrl' => $this->router->generate('payment.adyen.proxy-checkout-order'),
                        'checkoutOrderExpressUrl' => $this->router->generate(
                            'payment.adyen.proxy-checkout-order-express-product'
                        ),
                        'paymentHandleUrl' => $this->router->generate('payment.adyen.proxy-handle-payment'),
                        'paymentHandleExpressUrl' => $this->router->generate(
                            'payment.adyen.proxy-handle-payment-express-product'
                        ),
                        'paymentDetailsUrl' => $this->router->generate('payment.adyen.proxy-payment-details'),
                        'updatePaymentUrl' => $this->router->generate('payment.adyen.proxy-set-payment'),
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
                        'cancelOrderTransactionUrl' => $this->router->generate(
                            'payment.adyen.proxy-cancel-order-transaction',
                        ),
                        'expressCheckoutUpdatePaypalOrderUrl' =>
                            $this->router->generate('payment.adyen.proxy-express-checkout-update-paypal-order'),
                        'amount' => $amountInMinorUnits,
                        'countryCode' => $this->expressCheckoutService->getCountryCode(
                            $salesChannelContext->getCustomer(),
                            $salesChannelContext
                        ),
                        'userLoggedIn' => json_encode($userLoggedIn),
                        'affiliateCode' => $affiliateCode,
                        'campaignCode' => $campaignCode,
                        'googleMerchantId' => $this->configurationService
                            ->getGooglePayMerchantId($salesChannelContext->getSalesChannelId()),
                        'gatewayMerchantId' => $this->configurationService
                            ->getMerchantAccount($salesChannelContext->getSalesChannelId()),
                        'expressCheckoutConfigurationAvailable' => $expressCheckoutConfigurationAvailable,
                        'addGiftCardOption' => $this->configurationService->getAddGiftCardOption(),
                        'voucherBlockPosition' => $this->configurationService->getVoucherBlockPosition(),
                        'showVouchersSeparately' => json_encode($this->configurationService
                            ->getShowVouchersSeparately()),
                        'showVouchersCheckout' => json_encode(true),
                        'paypalOrderUrl' => $this->router->generate('payment.adyen.proxy-paypal-order'),
                        'paypalOrderFinalizeUrl' => $this->router->generate('payment.adyen.proxy-paypal-order-finalize')
                    ],
                    $expressCheckoutConfiguration
                )
            )
        );
    }

    /**
     * @param PageLoadedEvent $event
     *
     * @return void
     *
     */
    public function onProductPageLoaded(PageLoadedEvent $event): void
    {
        /** @var ProductPage $page */
        $page = $event->getPage();
        $salesChannelContext = $event->getSalesChannelContext();
        $productId = $page->getProduct()->getId();

        $userLoggedIn = $salesChannelContext->getCustomer() && !$salesChannelContext->getCustomer()->getGuest();

        $affiliateCode = $this->requestStack->getSession()->get(AffiliateTrackingListener::AFFILIATE_CODE_KEY);
        $campaignCode = $this->requestStack->getSession()->get(AffiliateTrackingListener::CAMPAIGN_CODE_KEY);

        $this->requestStack->getSession()->set('adyenSwContextToken', $salesChannelContext->getToken());

        $expressCheckoutConfigurationAvailable = true;
        $expressCheckoutConfiguration = [];
        $googlePayAvailable = $this->configurationService->isGooglePayExpressCheckoutEnabled();
        $payPalAvailable = $this->configurationService->isPayPalExpressCheckoutEnabled();
        $applePayAvailable = $this->configurationService->isApplePayExpressCheckoutEnabled();

        // If express checkout feature is disabled, returns empty payment method response
        if (!$googlePayAvailable && !$payPalAvailable && !$applePayAvailable) {
            $expressCheckoutConfigurationAvailable = false;
        }

        if ($expressCheckoutConfigurationAvailable) {
            $expressCheckoutConfiguration = $this->expressCheckoutService->getExpressCheckoutConfig(
                $productId,
                1,
                $salesChannelContext
            );
            if (array_key_exists('error', $expressCheckoutConfiguration)) {
                $expressCheckoutConfigurationAvailable = false;
                $expressCheckoutConfiguration = [];
            }
        }

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                array_merge(
                    $this->getComponentData($salesChannelContext),
                    [
                        'paymentStatusUrl' => $this->router->generate('payment.adyen.proxy-payment-status'),
                        'expressCheckoutConfigUrl' =>
                            $this->router->generate('payment.adyen.proxy-express-checkout-config'),
                        'checkoutOrderUrl' => $this->router->generate('payment.adyen.proxy-checkout-order'),
                        'checkoutOrderExpressUrl' => $this->router->generate(
                            'payment.adyen.proxy-checkout-order-express-product'
                        ),
                        'paymentHandleUrl' => $this->router->generate('payment.adyen.proxy-handle-payment'),
                        'paymentHandleExpressUrl' => $this->router->generate(
                            'payment.adyen.proxy-handle-payment-express-product'
                        ),
                        'paymentDetailsUrl' => $this->router->generate('payment.adyen.proxy-payment-details'),
                        'updatePaymentUrl' => $this->router->generate('payment.adyen.proxy-set-payment'),
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
                        'cancelOrderTransactionUrl' => $this->router->generate(
                            'payment.adyen.proxy-cancel-order-transaction',
                        ),
                        'expressCheckoutUpdatePaypalOrderUrl' =>
                            $this->router->generate('payment.adyen.proxy-express-checkout-update-paypal-order'),
                        'userLoggedIn' => json_encode($userLoggedIn),
                        'affiliateCode' => $affiliateCode,
                        'campaignCode' => $campaignCode,
                        'googleMerchantId' => $this->configurationService
                            ->getGooglePayMerchantId($salesChannelContext->getSalesChannelId()),
                        'gatewayMerchantId' => $this->configurationService
                            ->getMerchantAccount($salesChannelContext->getSalesChannelId()),
                        'expressCheckoutConfigurationAvailable' => $expressCheckoutConfigurationAvailable
                    ],
                    $expressCheckoutConfiguration
                )
            )
        );
    }

    /**
     * Adds vars to frontend template to be used in JS
     *
     * @param PageLoadedEvent $event
     */
    public function onCheckoutConfirmLoaded(PageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $selectedPaymentMethod = $salesChannelContext->getPaymentMethod();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $currencySymbol = $salesChannelContext->getCurrency()->getSymbol();
        $page = $event->getPage();
        $orderId = '';
        $affiliateCode = $this->requestStack->getSession()->get(AffiliateTrackingListener::AFFILIATE_CODE_KEY);
        $campaignCode = $this->requestStack->getSession()->get(AffiliateTrackingListener::CAMPAIGN_CODE_KEY);

        $this->requestStack->getSession()->set('adyenSwContextToken', $salesChannelContext->getToken());

        if (method_exists($page, 'getOrder')) {
            $orderId = $page->getOrder()->getId();
        }

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

        $filteredPaymentMethods = $this->paymentMethodsFilterService->filterShopwarePaymentMethods(
            $page->getPaymentMethods(),
            $salesChannelContext,
            $adyenPluginId,
            $paymentMethodsResponse
        );

        $giftcardDetails = $this->paymentStateDataService->getGiftcardTotalDiscountAndBalance(
            $salesChannelContext,
            $totalPrice
        );
        $paymentMethodId = $this->paymentMethodsFilterService->getGiftCardPaymentMethodId($salesChannelContext);

        $payInFullWithGiftcard = 0;
        if ($giftcardDetails['giftcardDiscount'] >= $totalPrice) { //if full amount is covered
            $payInFullWithGiftcard = 1;
        } else {
            $filteredPaymentMethods->remove($paymentMethodId); //Remove the PM from the list
        }

        $page->setPaymentMethods($filteredPaymentMethods);
        $paymentMethodsArray = $this->paymentMethodsService->getPaymentMethodsArray($paymentMethodsResponse);

        $paymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);

        $giftcards = $this->paymentMethodsFilterService->filterAdyenPaymentMethodsByType(
            $paymentMethods->getPaymentMethods() ?? [],
            'giftcard'
        );

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                array_merge(
                    $this->getComponentData($salesChannelContext),
                    [
                        'paymentStatusUrl' => $this->router->generate('payment.adyen.proxy-payment-status'),
                        'checkoutOrderUrl' => $this->router->generate('payment.adyen.proxy-checkout-order'),
                        'paymentHandleUrl' => $this->router->generate('payment.adyen.proxy-handle-payment'),
                        'paymentDetailsUrl' => $this->router->generate('payment.adyen.proxy-payment-details'),
                        'updatePaymentUrl' => $this->router->generate('payment.adyen.proxy-set-payment'),
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
                        'cancelOrderTransactionUrl' => $this->router->generate(
                            'payment.adyen.proxy-cancel-order-transaction',
                        ),
                        'languageId' => $salesChannelContext->getContext()->getLanguageId(),
                        'currency' => $currency,
                        'amount' => $amount,
                        'paymentMethodsResponse' => json_encode($paymentMethodsResponse),
                        'orderId' => $orderId,
                        'pluginId' => $this->adyenPluginProvider->getAdyenPluginId(),
                        'totalPrice' => $totalPrice,
                        'giftcardDiscount' => $giftcardDetails['giftcardDiscount'],
                        'currencySymbol' => $currencySymbol,
                        'payInFullWithGiftcard' => $payInFullWithGiftcard,
                        'storedPaymentMethods' => $paymentMethodsArray['storedPaymentMethods'] ?? [],
                        'selectedPaymentMethodHandler' => $selectedPaymentMethod->getFormattedHandlerIdentifier(),
                        'selectedPaymentMethodPluginId' => $selectedPaymentMethod->getPluginId(),
                        'displaySaveCreditCardOption' => $displaySaveCreditCardOption,
                        'billingAddressStreetHouse' => $this->paymentMethodsService->getSplitStreetAddressHouseNumber(
                            $salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet()
                        ),
                        'shippingAddressStreetHouse' => $this->paymentMethodsService->getSplitStreetAddressHouseNumber(
                            $salesChannelContext->getCustomer()->getActiveShippingAddress()->getStreet()
                        ),
                        'affiliateCode' => $affiliateCode,
                        'campaignCode' => $campaignCode,
                        'companyName' => $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCompany(),
                        'googleMerchantId' => $this->configurationService
                            ->getGooglePayMerchantId($salesChannelContext->getSalesChannelId()),
                        'gatewayMerchantId' => $this->configurationService
                            ->getMerchantAccount($salesChannelContext->getSalesChannelId()),
                        'voucherBlockPosition' => $this->configurationService->getVoucherBlockPosition(),
                        'showVouchersCheckout' => json_encode($this->configurationService->getShowVouchersCheckout()),
                        'showVouchersSeparately' => json_encode($this->configurationService
                            ->getShowVouchersSeparately()),
                        // checkout giftcards configuration
                        'totalInMinorUnits' => $amount,
                        'giftcardBalance' => $giftcardDetails['giftcardBalance'],
                        'checkBalanceUrl' => $this->router
                            ->generate('payment.adyen.proxy-check-balance'),
                        'setGiftcardUrl' => $this->router->generate('payment.adyen.proxy-store-giftcard-state-data'),
                        'removeGiftcardUrl' => $this->router
                            ->generate('payment.adyen.proxy-remove-giftcard-state-data'),
                        'fetchRedeemedGiftcardsUrl' => $this->router
                            ->generate('payment.adyen.proxy-fetch-redeemed-giftcards'),
                        'addGiftCardOption' => $this->configurationService->getAddGiftCardOption(),
                        'giftcards' => $giftcards,
                        'countryCode' => $this->expressCheckoutService->getCountryCode(
                            $salesChannelContext->getCustomer(),
                            $salesChannelContext
                        ),
                        'paypalOrderUrl' => $this->router->generate('payment.adyen.proxy-paypal-order'),
                        'paypalOrderFinalizeUrl' => $this->router->generate('payment.adyen.proxy-paypal-order-finalize')
                    ],
                    $this->getFingerprintParametersForRatepayMethod($salesChannelContext, $selectedPaymentMethod)
                )
            )
        );
    }

    /**
     * @param RequestEvent $event
     *
     * @return void
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (($request->attributes->get('_route') === 'frontend.account.edit-order.change-payment-method')
            && $request->request->has('adyenStateData')) {
            $this->contextSwitchRoute->switchContext(
                new RequestDataBag(
                    [
                        SalesChannelContextService::PAYMENT_METHOD_ID => $request->request->get('paymentMethodId'),
                        'adyenStateData' => $request->request->get('adyenStateData'),
                        'adyenOrigin' => $request->request->get('adyenOrigin'),
                    ]
                ),
                $this->salesChannelContextFactory->create(
                    $this->requestStack->getSession()->get('sw-context-token'),
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

    /**
     *
     * @param SalesChannelContext $salesChannelContext
     * @param PaymentMethodEntity $paymentMethod
     *
     * @return array
     */
    private function getFingerprintParametersForRatepayMethod(
        SalesChannelContext $salesChannelContext,
        PaymentMethodEntity $paymentMethod
    ): array {
        if ($paymentMethod->getFormattedHandlerIdentifier() ===
            'handler_adyen_ratepaydirectdebitpaymentmethodhandler' ||
            $paymentMethod->getFormattedHandlerIdentifier() === 'handler_adyen_ratepaypaymentmethodhandler'
        ) {
            return [
                'ratepay' => $this->ratePayFingerprintParamsProvider
                    ->getFingerprintParams($salesChannelContext->getSalesChannelId())
            ];
        }

        return [];
    }
}
