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
 */

namespace Adyen\Shopware\Service;

use Adyen\Model\Checkout\PaymentMethod;
use Adyen\Model\Checkout\PaymentMethodsResponse;
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\GiftCardPaymentMethodHandler;
use Adyen\Shopware\Handlers\GooglePayPaymentMethodHandler;
use Adyen\Shopware\Handlers\OneClickPaymentMethodHandler;
use Adyen\Shopware\Handlers\ApplePayPaymentMethodHandler;
use Adyen\Shopware\PaymentMethods\RatepayDirectdebitPaymentMethod;
use Adyen\Shopware\PaymentMethods\RatepayPaymentMethod;
use Adyen\Shopware\Service\Repository\ExpressCheckoutRepository;
use Adyen\Shopware\Util\Currency;
use Exception;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class PaymentMethodsFilterService
{
    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var PaymentMethodsService
     */
    private PaymentMethodsService $paymentMethodsService;

    /**
     * @var ExpressCheckoutRepository
     */
    private ExpressCheckoutRepository $expressCheckoutRepository;

    /**
     * @var Currency
     */
    private Currency $currencyUtil;

    /**
     * @var AbstractPaymentMethodRoute
     */
    private $paymentMethodRoute;

    private $paymentMethodRepository;

    /**
     * PaymentMethodsFilterService constructor.
     *
     * @param ConfigurationService $configurationService
     * @param PaymentMethodsService $paymentMethodsService
     * @param ExpressCheckoutRepository $expressCheckoutRepository
     * @param Currency $currency
     * @param AbstractPaymentMethodRoute $paymentMethodRoute
     * @param $paymentMethodRepository
     */
    public function __construct(
        ConfigurationService       $configurationService,
        PaymentMethodsService      $paymentMethodsService,
        ExpressCheckoutRepository  $expressCheckoutRepository,
        Currency                   $currency,
        AbstractPaymentMethodRoute $paymentMethodRoute,
        $paymentMethodRepository
    ) {
        $this->configurationService = $configurationService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentMethodRoute = $paymentMethodRoute;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->currencyUtil = $currency;
        $this->expressCheckoutRepository = $expressCheckoutRepository;
    }

    /**
     * Removes Adyen payment methods from the Shopware list if not present in Adyen's /paymentMethods response
     *
     * @param PaymentMethodCollection $originalPaymentMethods
     * @param SalesChannelContext $salesChannelContext
     *
     * @return PaymentMethodCollection
     */
    public function filterShopwarePaymentMethods(
        PaymentMethodCollection $originalPaymentMethods,
        SalesChannelContext $salesChannelContext,
        string $adyenPluginId,
        PaymentMethodsResponse $adyenPaymentMethods = null
    ): PaymentMethodCollection {
        if (empty($adyenPaymentMethods)) {
            // Get Adyen /paymentMethods response
            $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);
        }

        // If the /paymentMethods response returns empty, remove all Adyen payment methods from the list and return
        if (empty($adyenPaymentMethods->getPaymentMethods())) {
            return $originalPaymentMethods->filter(
                function (PaymentMethodEntity $item) use ($adyenPluginId) {
                    return $item->getPluginId() !== $adyenPluginId;
                }
            );
        }

        foreach ($originalPaymentMethods as $paymentMethodEntity) {
            //If this is an Adyen PM installed it will only be enabled if it's present in the /paymentMethods response
            if ($paymentMethodEntity->getPluginId() === $adyenPluginId) {
                /** @var AbstractPaymentMethodHandler $pmHandlerIdentifier */
                $pmHandlerIdentifier = $paymentMethodEntity->getHandlerIdentifier();
                $pmCode = $pmHandlerIdentifier::getPaymentMethodCode();
                $isSafari = preg_match('/^((?!chrome|android).)*safari/', strtolower($_SERVER['HTTP_USER_AGENT']));

                if ((
                        $pmCode === RatepayPaymentMethod::RATEPAY_PAYMENT_METHOD_TYPE ||
                        $pmCode === RatepayDirectdebitPaymentMethod::RATEPAY_DIRECTDEBIT_PAYMENT_METHOD_TYPE
                    ) &&
                    !$this->configurationService->getDeviceFingerprintSnippetId()) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                }

                if ($pmCode == OneClickPaymentMethodHandler::getPaymentMethodCode()) {
                    // For OneClick, remove it if /paymentMethod response has no stored payment methods
                    if (empty($adyenPaymentMethods->getStoredPaymentMethods())) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                } elseif ($pmCode == 'giftcard' && $pmHandlerIdentifier != GiftCardPaymentMethodHandler::class) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    // Remove ApplePay PM if the browser is not Safari
                } elseif ($pmCode == ApplePayPaymentMethodHandler::getPaymentMethodCode() && $isSafari !== 1) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                } else {
                    // For all other PMs, search in /paymentMethods response for payment method with matching `type`
                    $paymentMethodFoundInResponse = array_filter(
                        $adyenPaymentMethods->getPaymentMethods(),
                        function ($paymentMethod) use ($pmCode) {
                            /** @var PaymentMethod $paymentMethod */
                            return $paymentMethod->getType() === $pmCode;
                        }
                    );

                    // TODO: Following block will be removed after the deprecation of the `paywithgoogle` tx_variant.
                    if ($pmCode === GooglePayPaymentMethodHandler::getPaymentMethodCode()) {
                        $paywithgoogleTxvariant = 'paywithgoogle';
                        $paymentMethodFoundInResponse = array_merge(
                            $paymentMethodFoundInResponse,
                            array_filter(
                                $adyenPaymentMethods->getPaymentMethods(),
                                function ($paymentMethod) use ($paywithgoogleTxvariant) {
                                    /** @var PaymentMethod $paymentMethod */
                                    return $paymentMethod->getType() === $paywithgoogleTxvariant;
                                }
                            )
                        );
                    }

                    // Remove the PM if it isn't in the paymentMethods response
                    if (empty($paymentMethodFoundInResponse)) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                }
            }
        }

        return $originalPaymentMethods;
    }

    /**
     * Check if a payment method is available in the PaymentMethodCollection passed
     *
     * @param PaymentMethodCollection $paymentMethods
     * @param string $paymentMethodCode
     * @param string $adyenPluginId
     * @return bool
     */
    public function isPaymentMethodInCollection(
        PaymentMethodCollection $paymentMethods,
        string $paymentMethodCode,
        string $adyenPluginId
    ): bool {
        $filteredPaymentMethod = $paymentMethods->filter(
            function (PaymentMethodEntity $paymentMethod) use ($paymentMethodCode, $adyenPluginId) {
                return $paymentMethod->getPluginId() === $adyenPluginId &&
                    $paymentMethod->getHandlerIdentifier()::getPaymentMethodCode() === $paymentMethodCode;
            }
        )->first();

        return isset($filteredPaymentMethod);
    }

    public function getPaymentMethodInCollectionByBrand(
        PaymentMethodCollection $collection,
        string $brand,
        string $adyenPluginId
    ): ?PaymentMethodEntity {
        return $collection->filter(
            function (PaymentMethodEntity $paymentMethod) use ($brand, $adyenPluginId) {
                return $paymentMethod->getPluginId() === $adyenPluginId &&
                    $paymentMethod->getHandlerIdentifier()::getBrand() === $brand;
            }
        )->first();
    }

    public function getAvailableNonGiftcardsPaymentMethods(
        SalesChannelContext $context,
        ?PaymentMethodCollection $paymentMethods = null
    ) {
        if (is_null($paymentMethods)) {
            $paymentMethods = $this->getShopwarePaymentMethods($context);
        }

        foreach ($paymentMethods as $entity) {
            $methodHandler = $entity->getHandlerIdentifier();
            /** @var AbstractPaymentMethodHandler $methodHandler */
            if (method_exists($methodHandler, 'getPaymentMethodCode')
                && $methodHandler::getPaymentMethodCode() === 'giftcard') {
                // Remove giftcards from the actual collection
                $paymentMethods->remove($entity->getId());
            }
        }
    }

    /**
     * Retrieves available gift cards.
     *
     * @deprecated This method is deprecated and will be removed in future versions.
     *
     * @param SalesChannelContext $context The sales channel context.
     * @param array $adyenPaymentMethods Array of Adyen payment methods.
     * @param string $adyenPluginId The Adyen plugin ID.
     * @param PaymentMethodCollection|null $paymentMethods Collection of payment methods.
     *
     * @return PaymentMethodCollection The filtered payment methods.
    */
    public function getAvailableGiftcards(
        SalesChannelContext $context,
        array $adyenPaymentMethods,
        string $adyenPluginId,
        ?PaymentMethodCollection $paymentMethods = null
    ): PaymentMethodCollection {
        if (is_null($paymentMethods)) {
            $paymentMethods = $this->getShopwarePaymentMethods($context);
        }
        $filteredPaymentMethods = clone $paymentMethods;

        $giftcards = $this->filterAdyenPaymentMethodsByType($adyenPaymentMethods, 'giftcard');

        $brands = array_column($giftcards, 'brand');

        foreach ($filteredPaymentMethods as $entity) {
            $methodHandler = $entity->getHandlerIdentifier();

            /** @var AbstractPaymentMethodHandler $methodHandler */
            if ($entity->getPluginId() !== $adyenPluginId) {
                // Remove non-Adyen payment methods
                $filteredPaymentMethods->remove($entity->getId());
            } elseif ((method_exists($methodHandler, 'getPaymentMethodCode') &&
                    $methodHandler::getPaymentMethodCode() != 'giftcard') ||
                !in_array($methodHandler::getBrand(), $brands)) {
                // Remove non-giftcards and giftcards that are not in /paymentMethods response
                $filteredPaymentMethods->remove($entity->getId());
            } else {
                $brand = $methodHandler::getBrand();
                $entity->addExtension('adyenGiftcardData', new ArrayStruct(
                    array_filter($giftcards, function ($method) use ($brand) {
                        return $method['brand'] === $brand;
                    })
                ));
            }
        }

        return $filteredPaymentMethods;
    }

    public function filterAdyenPaymentMethodsByType(array $paymentMethods, string $type): array
    {
        return array_filter($paymentMethods, function ($item) use ($type) {
            return $item['type'] === $type;
        });
    }

    private function getShopwarePaymentMethods(SalesChannelContext $context): PaymentMethodCollection
    {
        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        return $this->paymentMethodRoute->load($request, $context, new Criteria())->getPaymentMethods();
    }

    public function getGiftCardPaymentMethodId(SalesChannelContext $context): ?string
    {
        $paymentMethodHandler =  GiftCardPaymentMethodHandler::class;

        $criteria = (new Criteria())->addFilter(new EqualsFilter(
            'handlerIdentifier',
            $paymentMethodHandler
        ));
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context->getContext())->first();

        // Return the payment method ID or null if not found
        return $paymentMethod ? $paymentMethod->getId() : null;
    }

    /**
     * Returns available filtered express checkout payment methods
     *
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     * @return PaymentMethodsResponse
     * @throws Exception
     */
    public function getAvailableExpressCheckoutPaymentMethods(
        Cart                $cart,
        SalesChannelContext $salesChannelContext
    ): PaymentMethodsResponse {
        $googlePayAvailable = $this->configurationService->isGooglePayExpressCheckoutEnabled();
        $payPalAvailable = $this->configurationService->isPayPalExpressCheckoutEnabled();
        $applePayAvailable = $this->configurationService->isApplePayExpressCheckoutEnabled();

        // If express checkout feature is disabled, returns empty payment method response
        if (!$googlePayAvailable && !$payPalAvailable && !$applePayAvailable) {
            return new PaymentMethodsResponse();
        }

        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];

            if ((!strpos($userAgent, 'Safari') || strpos($userAgent, 'Chrome'))) {
                $applePayAvailable = false;
            }
        }

        $googlePayInSalesChannel = false;
        $payPalInSalesChannel = false;
        $applePayInSalesChannel = false;

        $salesChannelPaymentMethodEntities = $this
            ->getSalesChannelPaymentMethodEntitiesFilteredByRules($cart, $salesChannelContext)
            ->getElements();

        foreach ($salesChannelPaymentMethodEntities as $paymentMethodEntity) {
            if ($paymentMethodEntity->getFormattedHandlerIdentifier()
                === 'handler_adyen_googlepaypaymentmethodhandler') {
                $googlePayInSalesChannel = true;
                continue;
            }

            if ($paymentMethodEntity->getFormattedHandlerIdentifier() === 'handler_adyen_paypalpaymentmethodhandler') {
                $payPalInSalesChannel = true;
                continue;
            }

            if ($paymentMethodEntity->getFormattedHandlerIdentifier()
                === 'handler_adyen_applepaypaymentmethodhandler') {
                $applePayInSalesChannel = true;
            }
        }

        $googlePayAvailable = $googlePayAvailable && $googlePayInSalesChannel;
        $payPalAvailable = $payPalAvailable && $payPalInSalesChannel;
        $applePayAvailable = $applePayAvailable && $applePayInSalesChannel;

        // If any of EC methods is not activated in current sales channel, returns empty payment method response
        if (!$googlePayAvailable && !$payPalAvailable && !$applePayAvailable) {
            return new PaymentMethodsResponse();
        }

        $availableShippingMethod = $this->expressCheckoutRepository
            ->fetchAvailableShippingMethods($salesChannelContext, $cart)->first();

        // If no shipping method is available for given cart shipping address, returns empty payment method response
        if (!$availableShippingMethod) {
            return new PaymentMethodsResponse();
        }

        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amount = $this->currencyUtil->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        $paymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext, '', $amount)
            ->getPaymentMethods();
        $allowedMethods = [];
        $allowedMethods['googlepay'] = (bool)$googlePayAvailable;
        $allowedMethods['paywithgoogle'] = (bool)$googlePayAvailable;
        $allowedMethods['paypal'] = (bool)$payPalAvailable;
        $allowedMethods['applepay'] = (bool)$applePayAvailable;

//         Filter methods by type and configuration
        $filteredMethods = array_values(
            array_filter($paymentMethods, function ($method) use ($allowedMethods) {
                $type = $method['type'];
                if (!array_key_exists($type, $allowedMethods) || !$allowedMethods[$type]) {
                    return false;
                }

                return $method;
            })
        );

        $paymentMethodsResponse = new PaymentMethodsResponse();
        $paymentMethodsResponse->setPaymentMethods($filteredMethods);

        return $paymentMethodsResponse;
    }

    /**
     * Finds a PaymentMethodEntity by its formattedHandlerIdentifier.
     *
     * @param string $formattedHandlerIdentifier
     * @param Context $context
     * @return PaymentMethodEntity|null
     */
    public function getPaymentMethodByFormattedHandler(
        string $formattedHandlerIdentifier,
        Context $context
    ): ?PaymentMethodEntity {

        $handlerMap = [
            'handler_adyen_googlepaypaymentmethodhandler' => 'Adyen\Shopware\Handlers\GooglePayPaymentMethodHandler',
            'handler_adyen_applepaypaymentmethodhandler' => 'Adyen\Shopware\Handlers\ApplePayPaymentMethodHandler',
            'handler_adyen_paypalpaymentmethodhandler' => 'Adyen\Shopware\Handlers\PayPalPaymentMethodHandler',
        ];

        $handlerIdentifier = $handlerMap[$formattedHandlerIdentifier];

        // Build criteria to filter by $handlerIdentifier
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier));

        /** @var PaymentMethodEntity|null $paymentMethod */
        return $this->paymentMethodRepository->search($criteria, $context)->first();
    }


    /**
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     *
     * @return PaymentMethodCollection
     */
    private function getSalesChannelPaymentMethodEntitiesFilteredByRules(
        Cart $cart,
        SalesChannelContext $salesChannelContext
    ): PaymentMethodCollection {
        $salesChannelPaymentMethodIs = $salesChannelContext->getSalesChannel()
            ->getPaymentMethodIds();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $salesChannelPaymentMethodIs));
        $criteria->addAssociation('availabilityRule');

        /** @var PaymentMethodCollection $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->search($criteria, $salesChannelContext->getContext())
            ->getEntities();

        return $paymentMethods->filter(function (PaymentMethodEntity $paymentMethodEntity) use (
            $cart,
            $salesChannelContext
        ) {
            $availabilityRule = $paymentMethodEntity->getAvailabilityRule();
            if (!$availabilityRule) {
                return true; // No rule means it's always available
            }

            return $availabilityRule->getPayload() &&
                $availabilityRule->getPayload()->match(new CartRuleScope($cart, $salesChannelContext));
        });
    }
}
