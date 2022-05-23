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
use Adyen\Shopware\Service\PaymentRequestService;
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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Shopware\Core\System\SalesChannel\SalesChannel\ContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

class PaymentSubscriber implements EventSubscriberInterface
{

    const ADYEN_DATA_EXTENSION_ID = 'adyenFrontendData';

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
     * @var ContainerInterface $container
     */
    private $container;

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
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;

    /**
     * @var ContextSwitchRoute
     */
    private $contextSwitchRoute;

    /**
     * @var AbstractSalesChannelContextFactory
     */
    private $salesChannelContextFactory;
    private PaymentRequestService $paymentRequestService;

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
     * @param ContainerInterface $container
     * @param CartPersisterInterface $cartPersister
     * @param CartCalculator $cartCalculator
     * @param ContextSwitchRoute $contextSwitchRoute
     * @param AbstractSalesChannelContextFactory $salesChannelContextFactory
     * @param Currency $currency
     * @param LoggerInterface $logger
     */
    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        PaymentStateDataService $paymentStateDataService,
        PaymentRequestService $paymentRequestService,
        RouterInterface $router,
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        PaymentMethodsService $paymentMethodsService,
        EntityRepositoryInterface $paymentMethodRepository,
        SessionInterface $session,
        ContainerInterface $container,
        CartPersisterInterface $cartPersister,
        CartCalculator $cartCalculator,
        ContextSwitchRoute $contextSwitchRoute,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        Currency $currency,
        LoggerInterface $logger
    ) {
        $this->paymentStateDataService = $paymentStateDataService;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->router = $router;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->session = $session;
        $this->container = $container;
        $this->cartPersister = $cartPersister;
        $this->cartCalculator = $cartCalculator;
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->currency = $currency;
        $this->logger = $logger;
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentRequestService = $paymentRequestService;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextSwitchEvent::class => 'onContextTokenUpdate',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            RequestEvent::class => 'onKernelRequest',
        ];
    }

    /**
     * @param SalesChannelContextSwitchEvent $event
     */
    public function onContextTokenUpdate(SalesChannelContextSwitchEvent $event)
    {
        // Clear state.data if payment method is updated
        if ($event->getRequestDataBag()->has('paymentMethodId')) {
            $this->removeCurrentStateData($event);
        }

        // Save state data, only if Adyen payment method is selected
        if ($event->getRequestDataBag()->get('adyenStateData')) {
            // Use payment method selected in the same request if available, otherwise get payment method from context
            $paymentMethodId = $event->getRequestDataBag()->get('paymentMethodId')
                ?? $event->getSalesChannelContext()->getPaymentMethod()->getId();
            /** @var PaymentMethodEntity $paymentMethod */
            $paymentMethod = $this->paymentMethodRepository->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('id', $paymentMethodId)),
                $event->getContext()
            )->first();
            if ($paymentMethod->getPluginId() === $this->adyenPluginProvider->getAdyenPluginId()) {
                $this->saveStateData($event, $paymentMethod);
            } else {
                $this->logger->error('No Adyen payment method selected, skipping state data save.');
                $this->session->getFlashBag()
                    ->add('danger', $this->trans('adyen.paymentMethodSelectionError'));
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
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        $page = $event->getPage();
        $orderId = '';
        if (method_exists($page, 'getOrder')) {
            $orderId = $page->getOrder()->getId();
        }
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amount = null;
        try {
            $cart = $this->cartCalculator->calculate(
                $this->cartPersister->load($salesChannelContext->getToken(), $salesChannelContext),
                $salesChannelContext
            );
            $amount = $this->currency->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        } catch (CartTokenNotFoundException $exception) {
            $cart = null;
            if (!empty($orderId)) {
                $amount = $this->currency->sanitize($page->getOrder()->getPrice()->getTotalPrice(), $currency);
            }
        }

        $paymentMethodsResponse = $this->paymentMethodsService->getPaymentMethods($salesChannelContext, $orderId);
        $filteredPaymentMethods = $this->paymentMethodsFilterService->filterShopwarePaymentMethods(
            $page->getPaymentMethods(),
            $salesChannelContext,
            $this->adyenPluginProvider->getAdyenPluginId(),
            $paymentMethodsResponse
        );

        $page->setPaymentMethods($filteredPaymentMethods);

        $stateDataIsStored = (bool)$this->paymentStateDataService->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        );

        $displaySaveCreditCardOption = $this->paymentMethodsFilterService->isPaymentMethodInCollection(
            $page->getPaymentMethods(),
            OneClickPaymentMethodHandler::getPaymentMethodCode(),
            $this->adyenPluginProvider->getAdyenPluginId(),
        );

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                [
                    'paymentStatusUrl' => $this->router->generate(
                        'store-api.action.adyen.payment-status'
                    ),
                    'checkoutOrderUrl' => $this->router->generate(
                        'store-api.checkout.cart.order'
                    ),
                    'paymentHandleUrl' => $this->router->generate(
                        'store-api.payment.handle'
                    ),
                    'paymentDetailsUrl' => $this->router->generate(
                        'store-api.action.adyen.payment-details'
                    ),
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
                    'languageId' => $salesChannelContext->getContext()->getLanguageId(),
                    'clientKey' => $this->configurationService->getClientKey($salesChannelId),
                    'locale' => $this->salesChannelRepository->getSalesChannelAssocLocale($salesChannelContext)
                        ->getLanguage()->getLocale()->getCode(),
                    'currency' => $currency,
                    'amount' => $amount,
                    'environment' => $this->configurationService->getEnvironment($salesChannelId),
                    'paymentMethodsResponse' => json_encode($paymentMethodsResponse),
                    'orderId' => $orderId,
                    'pluginId' => $this->adyenPluginProvider->getAdyenPluginId(),
                    'stateDataIsStored' => $stateDataIsStored,
                    'storedPaymentMethods' => $paymentMethodsResponse['storedPaymentMethods'] ?? [],
                    'selectedPaymentMethodHandler' => $paymentMethod->getFormattedHandlerIdentifier(),
                    'selectedPaymentMethodPluginId' => $paymentMethod->getPluginId(),
                    'displaySaveCreditCardOption' => $displaySaveCreditCardOption,
                    'billingAddressStreetHouse' => $this->paymentRequestService->getSplitStreetAddressHouseNumber($salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet()),
                    'shippingAddressStreetHouse' => $this->paymentRequestService->getSplitStreetAddressHouseNumber($salesChannelContext->getCustomer()->getActiveShippingAddress()->getStreet()),
                ]
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

    private function trans(string $snippet, array $parameters = []): string
    {
        return $this->container
            ->get('translator')
            ->trans($snippet, $parameters);
    }

    /**
     * Persists the Adyen payment state data on payment method confirmation/update
     *
     * @param SalesChannelContextSwitchEvent $event
     */
    private function saveStateData(SalesChannelContextSwitchEvent $event, PaymentMethodEntity $selectedPaymentMethod)
    {
        //State data from the frontend
        $stateData = $event->getRequestDataBag()->get('adyenStateData');

        //Convert the state data into an array
        $stateDataArray = json_decode($stateData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Payment state data is an invalid JSON: ' . json_last_error_msg());
            $this->session->getFlashBag()
                ->add('danger', $this->trans('adyen.paymentMethodSelectionError'));

            return;
        }

        $selectedPaymentMethodIsStoredPM =
            $selectedPaymentMethod->getFormattedHandlerIdentifier() == 'handler_adyen_oneclickpaymentmethodhandler';

        $stateDataIsStoredPM = !empty($stateDataArray["paymentMethod"]["storedPaymentMethodId"]);

        //Only store the state data if it matches the selected PM
        if ($stateDataIsStoredPM === $selectedPaymentMethodIsStoredPM) {
            try {
                $this->paymentStateDataService->insertPaymentStateData(
                    $event->getSalesChannelContext()->getToken(),
                    $event->getRequestDataBag()->get('adyenStateData'),
                    $event->getRequestDataBag()->get('adyenOrigin')
                );
            } catch (AdyenException $exception) {
                $this->session->getFlashBag()
                    ->add('danger', $this->trans('adyen.paymentMethodSelectionError'));

                return;
            }
        } else {
            //PM selected and state.data don't match, clear previous state.data
            $this->removeCurrentStateData($event);
        }
    }

    private function removeCurrentStateData(SalesChannelContextSwitchEvent $event)
    {
        $this->paymentStateDataService->deletePaymentStateDataFromContextToken(
            $event->getSalesChannelContext()->getToken()
        );
    }
}
