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
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\OriginKeyService;
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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

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
     * @var PluginIdProvider $pluginIdProvider
     */
    private $pluginIdProvider;

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
     * @var DataValidator
     */
    private $validator;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * @var string
     */
    private $adyenPluginId;

    /**
     * PaymentSubscriber constructor.
     *
     * @param PaymentStateDataService $paymentStateDataService
     * @param RouterInterface $router
     * @param OriginKeyService $originKeyService
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param PaymentMethodsService $paymentMethodsService
     * @param PluginIdProvider $pluginIdProvider
     * @param EntityRepositoryInterface $paymentMethodRepository
     * @param SessionInterface $session
     * @param ContainerInterface $container
     * @param CartPersisterInterface $cartPersister
     * @param CartCalculator $cartCalculator
     * @param DataValidator $validator
     * @param Currency $currency
     * @param LoggerInterface $logger
     */
    public function __construct(
        PaymentStateDataService $paymentStateDataService,
        RouterInterface $router,
        OriginKeyService $originKeyService,
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        PaymentMethodsService $paymentMethodsService,
        PluginIdProvider $pluginIdProvider,
        EntityRepositoryInterface $paymentMethodRepository,
        SessionInterface $session,
        ContainerInterface $container,
        CartPersisterInterface $cartPersister,
        CartCalculator $cartCalculator,
        DataValidator $validator,
        Currency $currency,
        LoggerInterface $logger
    ) {
        $this->paymentStateDataService = $paymentStateDataService;
        $this->router = $router;
        $this->originKeyService = $originKeyService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->pluginIdProvider = $pluginIdProvider;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->session = $session;
        $this->container = $container;
        $this->cartPersister = $cartPersister;
        $this->cartCalculator = $cartCalculator;
        $this->currency = $currency;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->adyenPluginId = $this->pluginIdProvider->getPluginIdByBaseClass(
            \Adyen\Shopware\AdyenPaymentShopware6::class,
            Context::createDefaultContext()
        );
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
            if (!$event->getRequestDataBag()->has('paymentMethodId')) {
                // Validate that payment method has previously been saved.
                $definition = new DataValidationDefinition('context_switch');
                $definition->add(
                    'paymentMethod',
                    new NotBlank(['message' => 'A payment method must be selected before saving payment state data.'])
                );
                $this->validator->validate($event->getSalesChannelContext()->getVars(), $definition);
            }
            // Use payment method selected in the same request if available, otherwise get payment method from context
            $paymentMethodId = $event->getRequestDataBag()->get('paymentMethodId')
                ?? $event->getSalesChannelContext()->getPaymentMethod()->getId();
            /** @var PaymentMethodEntity $paymentMethod */
            $paymentMethod = $this->paymentMethodRepository->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('id', $paymentMethodId)),
                $event->getContext()
            )->first();

            if ($paymentMethod->getPluginId() === $this->adyenPluginId) {
                $this->saveStateData($event, $paymentMethod);
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

        $filteredPaymentMethods = $this->filterShopwarePaymentMethods(
            $page->getPaymentMethods(),
            $salesChannelContext,
            $this->adyenPluginId
        );

        $page->setPaymentMethods($filteredPaymentMethods);

        $stateDataIsStored = (bool) $this->paymentStateDataService->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        );

        $paymentMethodsResponse = $this->paymentMethodsService->getPaymentMethods($salesChannelContext, $orderId);

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity(
                [
                    'paymentStatusUrl' => $this->router->generate(
                        'store-api.action.adyen.payment-status',
                        ['version' => 2]
                    ),
                    'checkoutOrderUrl' => $this->router->generate(
                        'store-api.checkout.cart.order',
                        ['version' => 2]
                    ),
                    'paymentHandleUrl' => $this->router->generate(
                        'store-api.payment.handle',
                        ['version' => 2]
                    ),
                    'paymentDetailsUrl' => $this->router->generate(
                        'store-api.action.adyen.payment-details',
                        ['version' => 2]
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
                    'editPaymentUrl' => $this->router->generate(
                        'store-api.order.set-payment',
                        ['version' => 2]
                    ),
                    'languageId' => $salesChannelContext->getContext()->getLanguageId(),
                    'originKey' => empty($this->configurationService->getClientKey($salesChannelId)) ?
                        $this->originKeyService->getOriginKeyForOrigin(
                            $this->salesChannelRepository->getSalesChannelUrl($salesChannelContext),
                            $salesChannelId
                        )->getOriginKey() :
                        false,
                    'clientKey' => $this->configurationService->getClientKey($salesChannelId),
                    'locale' => $this->salesChannelRepository->getSalesChannelAssocLocale($salesChannelContext)
                        ->getLanguage()->getLocale()->getCode(),
                    'currency' => $currency,
                    'amount' => $amount,
                    'environment' => $this->configurationService->getEnvironment($salesChannelId),
                    'paymentMethodsResponse' => json_encode($paymentMethodsResponse),
                    'orderId' => $orderId,
                    'pluginId' => $this->adyenPluginId,
                    'stateDataIsStored' => $stateDataIsStored,
                    'storedPaymentMethods' => $paymentMethodsResponse['storedPaymentMethods'] ?? [],
                    'selectedPaymentMethodHandler' => $paymentMethod->getFormattedHandlerIdentifier(),
                    'selectedPaymentMethodPluginId' => $paymentMethod->getPluginId()
                ]
            )
        );
    }

    /**
     * Removes Adyen payment methods from the Shopware list if not present in Adyen's /paymentMethods response
     *
     * @param PaymentMethodCollection $originalPaymentMethods
     * @param SalesChannelContext $salesChannelContext
     * @return PaymentMethodCollection
     */
    private function filterShopwarePaymentMethods(
        PaymentMethodCollection $originalPaymentMethods,
        SalesChannelContext $salesChannelContext,
        string $adyenPluginId
    ): PaymentMethodCollection {
        // Get Adyen /paymentMethods response
        $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);

        // If the /paymentMethods response returns empty, remove all Adyen payment methods from the list and return
        if (empty($adyenPaymentMethods['paymentMethods'])) {
            return $originalPaymentMethods->filter(function (PaymentMethodEntity $item) use ($adyenPluginId) {
                return $item->getPluginId() !== $adyenPluginId;
            });
        }

        foreach ($originalPaymentMethods as $paymentMethodEntity) {
            //If this is an Adyen PM installed it will only be enabled if it's present in the /paymentMethods response
            /** @var PaymentMethodEntity $paymentMethodEntity */
            if ($paymentMethodEntity->getPluginId() === $adyenPluginId) {
                $pmHandlerIdentifier = $paymentMethodEntity->getHandlerIdentifier();
                $pmCode = $pmHandlerIdentifier::getPaymentMethodCode();

                if ($pmCode == OneClickPaymentMethodHandler::getPaymentMethodCode()) {
                    // For OneClick, remove it if /paymentMethod response has no stored payment methods
                    if (empty($adyenPaymentMethods[OneClickPaymentMethodHandler::getPaymentMethodCode()])) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                } else {
                    // For all other PMs, search in /paymentMethods response for payment method with matching `type`
                    $paymentMethodFoundInResponse = array_filter(
                        $adyenPaymentMethods['paymentMethods'],
                        function ($value) use ($pmCode) {
                            return $value['type'] == $pmCode;
                        }
                    );

                    // Remove the PM if it isn't in the paymentMethods response
                    if (empty($paymentMethodFoundInResponse)) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                }
            }
        }
        return $originalPaymentMethods;
    }

    private function trans(string $snippet, array $parameters = []): string
    {
        return $this->container
            ->get('translator')
            ->trans($snippet, $parameters);
    }

    /**
     * Persists the Adyen payment state data on payment method confirmation/update
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
        if ($stateDataIsStoredPM == $selectedPaymentMethodIsStoredPM) {
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
