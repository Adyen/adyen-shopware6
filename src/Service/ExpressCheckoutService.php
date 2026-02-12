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

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\DeliveryMethod;
use Adyen\Model\Checkout\PaypalUpdateOrderRequest;
use Adyen\Model\Checkout\PaypalUpdateOrderResponse;
use Adyen\Service\Checkout\UtilityApi;
use Adyen\Shopware\Exception\ResolveCountryException;
use Adyen\Shopware\Service\Repository\ExpressCheckoutRepository;
use Adyen\Shopware\Exception\ResolveShippingMethodException;
use Adyen\Shopware\Util\Currency;
use Exception;
use JsonException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class ExpressCheckoutService
{
    /** @var CartService */
    private CartService $cartService;

    /**
     * @var ExpressCheckoutRepository
     */
    private ExpressCheckoutRepository $expressCheckoutRepository;

    /**
     * @var PaymentMethodsFilterService
     */
    private PaymentMethodsFilterService $paymentMethodsFilterService;

    /**
     * @var Currency
     */
    private Currency $currencyUtil;

    /**
     * @var ClientService
     */
    protected ClientService $clientService;

    /**
     * @var SalesChannelContextPersister
     */
    private SalesChannelContextPersister $contextPersister;

    /**
     * @var EntityRepository
     */
    private EntityRepository $orderRepository;

    /** @var OrderConverter */
    private OrderConverter $orderConverter;

    /** @var SalesChannelContextService $salesChannelContextService */
    private SalesChannelContextService $salesChannelContextService;

    /**
     * @param CartService $cartService
     * @param ExpressCheckoutRepository $expressCheckoutRepository
     * @param PaymentMethodsFilterService $paymentMethodsFilterService
     * @param ClientService $clientService
     * @param Currency $currencyUtil
     * @param SalesChannelContextPersister $contextPersister
     * @param EntityRepository $orderRepository
     * @param OrderConverter $orderConverter
     * @param SalesChannelContextService $salesChannelContextService
     */
    public function __construct(
        CartService $cartService,
        ExpressCheckoutRepository $expressCheckoutRepository,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        ClientService $clientService,
        Currency $currencyUtil,
        SalesChannelContextPersister $contextPersister,
        EntityRepository $orderRepository,
        OrderConverter $orderConverter,
        SalesChannelContextService $salesChannelContextService
    ) {
        $this->cartService = $cartService;
        $this->expressCheckoutRepository = $expressCheckoutRepository;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->clientService = $clientService;
        $this->currencyUtil = $currencyUtil;
        $this->contextPersister = $contextPersister;
        $this->orderRepository = $orderRepository;
        $this->orderConverter = $orderConverter;
        $this->salesChannelContextService = $salesChannelContextService;
    }

    /**
     * @param string $token
     * @param string $salesChannelId
     *
     * @return SalesChannelContext
     */
    public function getSalesChannelContext(string $token, string $salesChannelId): SalesChannelContext
    {
        return $this->salesChannelContextService->get(
            new SalesChannelContextServiceParameters(
                $salesChannelId,
                $token
            )
        );
    }

    /**
     * Retrieves the express checkout configuration on the product page.
     *
     * @param string $productId The ID of the product.
     * @param int $quantity The quantity of the product.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @param array $newShipping Optional new shipping method details.
     * @param string $formattedHandlerIdentifier
     *
     * @return array The configuration for express checkout.
     *
     * @throws Exception
     */
    public function getExpressCheckoutConfig(
        string $productId,
        int $quantity,
        SalesChannelContext $salesChannelContext,
        array $newAddress = [],
        array $newShipping = [],
        string $formattedHandlerIdentifier = ''
    ): array {
        try {
            $cartData = $this->createCart(
                $productId,
                $quantity,
                $salesChannelContext,
                $newAddress,
                $newShipping,
                $formattedHandlerIdentifier
            );

            /** @var Cart $cart */
            $cart = $cartData['cart'];

            $currency = $salesChannelContext->getCurrency()->getIsoCode();
            $amountInMinorUnits = $this->currencyUtil->sanitize($cart->getPrice()->getTotalPrice(), $currency);

            // Available shipping methods for the given address
            $shippingMethods = $this->getFormatedShippingMethods($cartData, $currency);

            if (empty($shippingMethods)) {
                throw new ResolveShippingMethodException('No shipping method found!');
            }

            // Available payment methods
            $paymentMethods = $cartData['paymentMethods'];

            // Delete temporary cart for product
            if ($productId !== '-1') {
                $this->cartService->deleteCart($cartData['updatedSalesChannelContext']);
            }

            return [
                'currency' => $currency,
                'amount' => $amountInMinorUnits,
                'countryCode' => $this->getCountryCode(
                    $salesChannelContext->getCustomer(),
                    $salesChannelContext
                ),
                'paymentMethodsResponse' => json_encode($paymentMethods),
                'shippingMethodsResponse' => array_values($shippingMethods),
            ];
        } catch (ResolveCountryException $exception) {
            return [
                'error' => 'ResolveCountryException',
                'message' => $exception->getMessage()
            ];
        } catch (ResolveShippingMethodException $exception) {
            return [
                'error' => 'ResolveShippingMethodException',
                'message' => $exception->getMessage()
            ];
        }
    }

    /**
     *
     * Retrieves the customer's active address if the customer exists,
     * otherwise, returns the default country of the sales channel.
     *
     * @param CustomerEntity|null $customer
     * @param SalesChannelContext $salesChannelContext
     *
     * @return string
     */
    public function getCountryCode(?CustomerEntity $customer, SalesChannelContext $salesChannelContext): string
    {
        return $this->expressCheckoutRepository->getCountryCode($customer, $salesChannelContext);
    }


    /**
     * Creates a cart for PayPal Express Checkout with the provided product.
     *
     * @param string $productId The ID of the product.
     * @param int $quantity The quantity of the product.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param string $formattedHandlerIdentifier
     *
     * @return array The cart, shipping methods, selected shipping method, and payment methods.
     *
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     */
    public function createCartForPayPalGuestExpressCheckout(
        string $productId,
        int $quantity,
        SalesChannelContext $salesChannelContext,
        string $formattedHandlerIdentifier = '',
    ): array {
        $customer = $salesChannelContext->getCustomer();
        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);

        if ($productId !== "-1") {
            $lineItem = new LineItem($productId, 'product', $productId, $quantity);
            $cart = $this->cartService->createNew($tokenNew = Uuid::randomHex());
            $cart->add($lineItem);
            $token = $tokenNew;
        }
        $country = $this->expressCheckoutRepository->resolveCountry($salesChannelContext);
        $shippingLocation = ShippingLocation::createFromCountry($country);

        return $this->returnExpressCheckoutCartData(
            $cart,
            $token,
            $formattedHandlerIdentifier,
            [],
            $shippingLocation,
            $customer,
            $salesChannelContext
        );
    }

    /**
     * Creates a cart with the provided product and calculates it with the resolved shipping location and method.
     *
     * @param string $productId The ID of the product.
     * @param int $quantity The quantity of the product.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @param array $newShipping Optional new shipping method details.
     *
     * @return array The cart, shipping methods, selected shipping method, and payment methods.
     * @throws ResolveCountryException|ResolveShippingMethodException
     */
    public function createCart(
        string $productId,
        int $quantity,
        SalesChannelContext $salesChannelContext,
        array $newAddress = [],
        array $newShipping = [],
        string $formattedHandlerIdentifier = '',
        string $guestEmail = '',
        bool $makeNewCustomer = false,
        bool $createNewAddress = false,
        ?string $token = null
    ): array {
        $customer = $salesChannelContext->getCustomer();
        !$token && $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);

        if ($productId !== "-1") { // product page
            // Creating new cart with the product from the product page
            $lineItem = new LineItem($productId, 'product', $productId, $quantity);
            $cart = $this->cartService->createNew($tokenNew = Uuid::randomHex());
            $cart->add($lineItem);
            $token = $tokenNew;
        }

        // Guest user in session during order creation
        if ($createNewAddress) {
            return $this->returnExpressCheckoutCartDataForGuestUserInSession(
                $cart,
                $token,
                $newAddress,
                $newShipping,
                $formattedHandlerIdentifier,
                $customer,
                $salesChannelContext
            );
        }

        // Guest user without session during order creation
        if ($makeNewCustomer) {
            return $this->returnExpressCheckoutCartDataForGuestUserWithoutSession(
                $cart,
                $token,
                $newAddress,
                $newShipping,
                $formattedHandlerIdentifier,
                $guestEmail,
                $salesChannelContext
            );
        }

        // Resolving shipping location for customer user
        if ($customer) {
            $shippingLocation = ShippingLocation::createFromAddress($customer->getDefaultShippingAddress());
        }

        // Resolving shipping location for guest user
        if (!$customer || $customer->getGuest()) {
            $country = $this->expressCheckoutRepository->resolveCountry($salesChannelContext, $newAddress);
            $shippingLocation = ShippingLocation::createFromCountry($country);
        }

        return $this->returnExpressCheckoutCartData(
            $cart,
            $token,
            $formattedHandlerIdentifier,
            $newShipping,
            $shippingLocation,
            $customer,
            $salesChannelContext
        );
    }

    /**
     * Updates the SalesChannelContext for guest customer.
     *
     * @param ?string $customerId The ID of the customer whose context should be updated.
     * @param SalesChannelContext $salesChannelContext The existing sales channel context to be updated.
     *
     * @return SalesChannelContext The updated SalesChannelContext with the customer's details.
     * @throws Exception If the customer cannot be found.
     *
     */
    public function changeContext(?string $customerId, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        // Fetch the customer by ID
        if (!$customerId) {
            $salesChannelContext = $this->createContext(
                $salesChannelContext,
                $salesChannelContext->getToken(),
                $salesChannelContext->getShippingLocation(),
                $salesChannelContext->getPaymentMethod(),
            );

            $this->contextPersister->save(
                $salesChannelContext->getToken(),
                [
                    SalesChannelContextService::PAYMENT_METHOD_ID => $salesChannelContext->getPaymentMethod()->getId()
                ],
                $salesChannelContext->getSalesChannel()->getId()
            );

            return $salesChannelContext;
        }

        $customer = $this->expressCheckoutRepository->findCustomerById($customerId, $salesChannelContext);

        // Create the shipping location from the customer's billing address
        $shippingLocation = ShippingLocation::createFromAddress($customer->getDefaultBillingAddress());

        // Update the context
        $salesChannelContext = $this->createContext(
            $salesChannelContext,
            $salesChannelContext->getToken(),
            $shippingLocation,
            $salesChannelContext->getPaymentMethod(),
            $customer
        );

        $this->contextPersister->save(
            $salesChannelContext->getToken(),
            [
                SalesChannelContextService::CUSTOMER_ID => $customerId,
                SalesChannelContextService::PAYMENT_METHOD_ID => $salesChannelContext->getPaymentMethod()->getId()
            ],
            $salesChannelContext->getSalesChannel()->getId()
        );

        return $salesChannelContext;
    }

    /**
     * @param string $cartToken
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     * @param array $newAddress
     * @param array $newShipping
     *
     * @return PaypalUpdateOrderResponse
     *
     * @throws AdyenException
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException|JsonException
     */
    public function paypalUpdateOrder(
        string $cartToken,
        array $data,
        SalesChannelContext $salesChannelContext,
        array $newAddress = [],
        array $newShipping = []
    ): PaypalUpdateOrderResponse {
        $cartData = $this->createCart(
            '-1',
            -1,
            $salesChannelContext,
            $newAddress,
            $newShipping,
            'handler_adyen_paypalpaymentmethodhandler',
            '',
            false,
            false,
            $cartToken
        );
        /** @var Cart $cart */
        $cart = $cartData['cart'];

        $utilityApiService = new UtilityApi(
            $this->clientService->getClient($salesChannelContext->getSalesChannel()->getId())
        );

        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amountInMinorUnits = $this->currencyUtil->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        $amount = new Amount();
        $amount->setCurrency($currency);
        $amount->setValue($amountInMinorUnits);
        $data['amount'] = $amount;
        $paypalUpdateOrderRequest = new PaypalUpdateOrderRequest($data);

        $deliveryMethods = [];
        $shippingMethods = $this->getFormatedShippingMethods($cartData, $currency);

        if (empty($shippingMethods)) {
            throw new ResolveShippingMethodException('No shipping method found!');
        }

        foreach ($shippingMethods as $shippingMethod) {
            $deliveryMethods[] = new DeliveryMethod(
                [
                    'amount' => new Amount($shippingMethod),
                    'description' => $shippingMethod['label'],
                    'reference' => $shippingMethod['id'],
                    'selected' => (!empty($newShipping) && $newShipping['id'] === $shippingMethod['id']) ?
                        $newShipping['selected'] : $shippingMethod['selected'],
                    'type' => 'SHIPPING',
                ]
            );
        }

        $paypalUpdateOrderRequest->setDeliveryMethods($deliveryMethods);

        if (!empty($newShipping)) {
            $this->cartService->recalculate($cart, $cartData['updatedSalesChannelContext']);
            $this->setShippingInContext($cartData['updatedSalesChannelContext']);
        }

        return $utilityApiService
            ->updatesOrderForPaypalExpressCheckout($paypalUpdateOrderRequest);
    }

    /**
     * @param Request $request
     * @param string $orderId
     * @param SalesChannelContext $salesChannelContext
     * @param array $newAddress
     * @param array $newShipping
     *
     * @return void
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     */
    public function updateShopOrder(
        Request $request,
        string $orderId,
        SalesChannelContext $salesChannelContext,
        array $newAddress = [],
        array $newShipping = []
    ): void {
        /** @var OrderEntity $order */
        $order = $this->expressCheckoutRepository->getOrderById($orderId, $salesChannelContext->getContext());
        $cartData = $this->createCart(
            '-1',
            1,
            $salesChannelContext,
            $newAddress,
            $newShipping,
            'handler_adyen_paypalpaymentmethodhandler',
            '',
            false,
            false
        );
        /** @var SalesChannelContext $updatedSalesChannelContext */
        $updatedSalesChannelContext = $cartData['updatedSalesChannelContext'];
        $liveContext = $updatedSalesChannelContext->getContext();

        $versionId = null;
        $liveContext->scope(Context::CRUD_API_SCOPE, function (Context $scopedCtx) use ($orderId, &$versionId): void {
            $versionId = $this->orderRepository->createVersion($orderId, $scopedCtx);
        });

        $contextWithNewVersion = $liveContext->createWithVersionId($versionId);

        /** @var OrderEntity $orderWithNewVersion */
        $orderWithNewVersion = $this->expressCheckoutRepository->getOrderById(
            $orderId,
            $contextWithNewVersion
        );
        $cartFromOrder = $this->orderConverter->convertToCart($orderWithNewVersion, $contextWithNewVersion);
        $cartFromOrder->setRuleIds($contextWithNewVersion->getRuleIds());
        $recalculatedCart = $this->cartService->recalculate($cartFromOrder, $updatedSalesChannelContext);

        $newOrderData = $this->orderConverter->convertToOrder(
            $recalculatedCart,
            $updatedSalesChannelContext,
            new OrderConversionContext()
        );
        $newOrderData['id'] = $order->getId();

        $orderDelivery = $order->getDeliveries()?->first();
        if ($orderDelivery) {
            $deliveryFromNewOrderData = ($newOrderData['deliveries'] && count($newOrderData['deliveries']) > 0) ?
                $newOrderData['deliveries'][0] : [];
            $deliveryFromNewOrderData['id'] = $orderDelivery->getId();
            $newOrderData['deliveries'][0] = $deliveryFromNewOrderData;
        }

        $orderTransaction = $order->getTransactions()?->first();
        if ($orderTransaction) {
            $transactionFromNewOrderData = ($newOrderData['transactions'] && count($newOrderData['transactions']) > 0) ?
                $newOrderData['transactions'][0] : [];
            $transactionFromNewOrderData['id'] = $orderTransaction->getId();
            $newOrderData['transactions'][0] = $transactionFromNewOrderData;
        }

        $this->expressCheckoutRepository->upsertOrder($newOrderData, $contextWithNewVersion);

        $liveContext->scope(Context::SYSTEM_SCOPE, function (Context $scopedCtx) use ($versionId): void {
            $this->orderRepository->merge($versionId, $scopedCtx);
        });

        $this->cartService->deleteCart($updatedSalesChannelContext);
    }

    /**
     * @param OrderEntity $order
     * @param array $newAddress
     * @param array $newShipping
     * @param string $formattedHandlerIdentifier
     * @param CustomerEntity $customer
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     */
    private function returnExpressCartDataForPayPal(
        OrderEntity $order,
        array $newAddress,
        array $newShipping,
        string $formattedHandlerIdentifier,
        CustomerEntity $customer,
        SalesChannelContext $salesChannelContext
    ): array {
        $cart = $this->orderConverter->convertToCart($order, $salesChannelContext->getContext());
        $cart->setRuleIds($salesChannelContext->getRuleIds());

        $shippingLocation = $salesChannelContext->getShippingLocation();

        if ($newAddress) {
            $this->expressCheckoutRepository->resolveCountry($salesChannelContext, $newAddress);
            $address = $this->expressCheckoutRepository->updateOrderAddressAndCustomer(
                $newAddress,
                $customer,
                $order->getBillingAddressId(),
                $order->getOrderCustomer() ? $order->getOrderCustomer()->getId() : '',
                $salesChannelContext
            );

            $shippingLocation = ShippingLocation::createFromAddress($address);
        }

        return $this->returnExpressCheckoutCartData(
            $cart,
            $salesChannelContext->getToken(),
            $formattedHandlerIdentifier,
            $newShipping,
            $shippingLocation,
            $customer,
            $salesChannelContext
        );
    }

    /**
     * @param Cart $cart
     * @param string $token
     * @param array $newAddress
     * @param array $newShipping
     * @param CustomerEntity $customer
     * @param string $formattedHandlerIdentifier
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     *
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     */
    private function returnExpressCheckoutCartDataForGuestUserInSession(
        Cart $cart,
        string $token,
        array $newAddress,
        array $newShipping,
        string $formattedHandlerIdentifier,
        CustomerEntity $customer,
        SalesChannelContext $salesChannelContext
    ): array {
        $shippingLocation = $salesChannelContext->getShippingLocation();

        if (!empty($newAddress)) {
            $guestCustomerAddress = $this->expressCheckoutRepository->createAddress(
                $newAddress,
                $customer,
                $salesChannelContext
            );

            $this->expressCheckoutRepository->updateDefaultCustomerAddress(
                $guestCustomerAddress,
                $customer,
                $salesChannelContext
            );

            $this->expressCheckoutRepository->updateGuestName(
                $newAddress,
                $customer,
                $salesChannelContext
            );

            $shippingLocation = ShippingLocation::createFromAddress($guestCustomerAddress);
            $customer->setActiveBillingAddress($guestCustomerAddress);
            $customer->setActiveShippingAddress($guestCustomerAddress);
        }

        return $this->returnExpressCheckoutCartData(
            $cart,
            $token,
            $formattedHandlerIdentifier,
            $newShipping,
            $shippingLocation,
            $customer,
            $salesChannelContext
        );
    }

    /**
     * @param Cart $cart
     * @param string $token
     * @param array $newAddress
     * @param array $newShipping
     * @param string $formattedHandlerIdentifier
     * @param string $guestEmail
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     *
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     */
    private function returnExpressCheckoutCartDataForGuestUserWithoutSession(
        Cart $cart,
        string $token,
        array $newAddress,
        array $newShipping,
        string $formattedHandlerIdentifier,
        string $guestEmail,
        SalesChannelContext $salesChannelContext
    ): array {
        $customer = $this->expressCheckoutRepository->createGuestCustomer(
            $salesChannelContext,
            $guestEmail,
            $newAddress
        );
        $shippingLocation = ShippingLocation::createFromAddress($customer->getDefaultBillingAddress());

        return $this->returnExpressCheckoutCartData(
            $cart,
            $token,
            $formattedHandlerIdentifier,
            $newShipping,
            $shippingLocation,
            $customer,
            $salesChannelContext
        );
    }

    /**
     * @param Cart $cart
     * @param string $token
     * @param ShippingLocation $shippingLocation
     * @param CustomerEntity|null $customer
     * @param string $formattedHandlerIdentifier
     * @param SalesChannelContext $salesChannelContext
     * @param array $newShipping
     *
     * @return array
     *
     * @throws ResolveShippingMethodException
     * @throws Exception
     */
    private function returnExpressCheckoutCartData(
        Cart $cart,
        string $token,
        string $formattedHandlerIdentifier,
        array $newShipping,
        ShippingLocation $shippingLocation,
        ?CustomerEntity $customer,
        SalesChannelContext $salesChannelContext
    ): array {
        // Get payment method
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        if ($formattedHandlerIdentifier !== '') {
            // Express checkout payment method
            $paymentMethod = $this->paymentMethodsFilterService
                ->getPaymentMethodByFormattedHandler($formattedHandlerIdentifier, $salesChannelContext->getContext());
        }

        // Create updated context
        $updatedSalesChannelContext = $this->createContext(
            $salesChannelContext,
            $token,
            $shippingLocation,
            $paymentMethod,
            $customer
        );

        // recalculate the cart
        $cart = $this->cartService->recalculate($cart, $updatedSalesChannelContext);

        // Fetch available shipping methods
        $shippingMethods = $this->expressCheckoutRepository
            ->fetchAvailableShippingMethods($updatedSalesChannelContext, $cart);

        // Fetch shipping method
        $shippingMethod = $this->resolveShippingMethod($shippingMethods, $newShipping);

        // Recreate context with selected shipping method
        $updatedSalesChannelContext = $this->createContext(
            $salesChannelContext,
            $token,
            $shippingLocation,
            $paymentMethod,
            $customer,
            $shippingMethod
        );

        // Recalculate the cart
        $cart = $this->cartService->recalculate($cart, $updatedSalesChannelContext);

        // Fetch available express checkout payment methods
        $filteredPaymentMethods = $this->paymentMethodsFilterService
            ->getAvailableExpressCheckoutPaymentMethods($cart, $updatedSalesChannelContext);

        return [
            'cart' => $cart,
            'shippingMethods' => $shippingMethods,
            'shippingMethod' => $shippingMethod,
            'shippingLocation' => $shippingLocation,
            'paymentMethods' => $filteredPaymentMethods,
            'updatedSalesChannelContext' => $updatedSalesChannelContext,
            'customerId' => $customer ? $customer->getId() : '',
        ];
    }

    /**
     * Resolves the shipping method based on the provided cart and new shipping details.
     *
     * @param ShippingMethodCollection $filteredMethods
     * @param array $newShipping Optional new shipping method details.
     *
     * @return ShippingMethodEntity The resolved shipping method.
     *
     * @throws ResolveShippingMethodException
     */
    private function resolveShippingMethod(
        ShippingMethodCollection $filteredMethods,
        array $newShipping
    ): ShippingMethodEntity {
        // Check if a specific shipping method ID is provided in the new shipping data
        $newShippingMethodId = $newShipping['id'] ?? null;

        // Attempt to get the shipping method based on the ID or fallback to the first available method
        $shippingMethod = $newShippingMethodId
            ? $filteredMethods->get($newShippingMethodId) : $filteredMethods->first();

        // If no shipping method is resolved, throw an exception
        if (!$shippingMethod) {
            throw new ResolveShippingMethodException("No shipping method found!");
        }

        return $shippingMethod;
    }

    /**
     * Creates a SalesChannelContext
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param string $token The token to be associated with the new context.
     * @param ShippingLocation $shippingLocation The shipping location to be used.
     * @param PaymentMethodEntity $paymentMethod The payment method entity to set in the context.
     * @param CustomerEntity|null $customer The customer entity (optional).
     * @param ShippingMethodEntity|null $shippingMethod The optional shipping method entity to set in the context.
     *
     * @return SalesChannelContext The created SalesChannelContext.
     */
    public function createContext(
        SalesChannelContext $salesChannelContext,
        string $token,
        ShippingLocation $shippingLocation,
        PaymentMethodEntity $paymentMethod,
        ?CustomerEntity $customer = null,
        ?ShippingMethodEntity $shippingMethod = null
    ): SalesChannelContext {
        return new SalesChannelContext(
            $salesChannelContext->getContext(),
            $token,
            null,
            $salesChannelContext->getSalesChannel(),
            $salesChannelContext->getCurrency(),
            $salesChannelContext->getCurrentCustomerGroup(),
            $salesChannelContext->getTaxRules(),
            $paymentMethod,
            $shippingMethod ?? $salesChannelContext->getShippingMethod(),
            $shippingLocation,
            $customer,
            $salesChannelContext->getItemRounding(),
            $salesChannelContext->getTotalRounding()
        );
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param string $token
     * @param array $newAddress
     *
     * @return SalesChannelContext
     *
     * @throws ResolveCountryException
     */
    public function createCustomerAndUpdateContext(
        SalesChannelContext $salesChannelContext,
        string $token,
        array $newAddress
    ): SalesChannelContext {
        $customer = $this->expressCheckoutRepository->createGuestCustomer(
            $salesChannelContext,
            $newAddress['email'] ?? '',
            $newAddress
        );

        $guestCustomerAddress = $this->expressCheckoutRepository->createAddress(
            $newAddress,
            $customer,
            $salesChannelContext
        );

        $this->expressCheckoutRepository->updateDefaultCustomerAddress(
            $guestCustomerAddress,
            $customer,
            $salesChannelContext
        );

        $this->expressCheckoutRepository->updateGuestName(
            $newAddress,
            $customer,
            $salesChannelContext
        );

        $shippingLocation = ShippingLocation::createFromAddress($guestCustomerAddress);
        $customer->setActiveBillingAddress($guestCustomerAddress);
        $customer->setActiveShippingAddress($guestCustomerAddress);

        return new SalesChannelContext(
            $salesChannelContext->getContext(),
            $token,
            null,
            $salesChannelContext->getSalesChannel(),
            $salesChannelContext->getCurrency(),
            $salesChannelContext->getCurrentCustomerGroup(),
            $salesChannelContext->getTaxRules(),
            $salesChannelContext->getPaymentMethod(),
            $salesChannelContext->getShippingMethod(),
            $shippingLocation,
            $customer,
            $salesChannelContext->getItemRounding(),
            $salesChannelContext->getTotalRounding(),
            $salesChannelContext->getAreaRuleIds()
        );
    }

    /**
     * @param array $cartData
     * @param string $currency
     *
     * @return array
     *
     * @throws Exception
     */
    private function getFormatedShippingMethods(array $cartData, string $currency): array
    {
        /** @var ShippingMethodEntity $selectedShippingMethod */
        $selectedShippingMethod = $cartData['shippingMethod'];
        /** @var Cart $cart */
        $cart = $cartData['cart'];
        /** @var SalesChannelContext $salesChannelContext */
        $salesChannelContext = $cartData['updatedSalesChannelContext'];
        $shippingMethods = $cartData['shippingMethods'];

        if (empty($shippingMethods)) {
            return [];
        }

        $availableShippingMethods = array_map(function (ShippingMethodEntity $method) use (
            $currency,
            $salesChannelContext,
            $selectedShippingMethod,
            $cart
        ) {
            $salesChannelWithCurrentShippingMethod = $this->createContext(
                $salesChannelContext,
                $salesChannelContext->getToken(),
                $salesChannelContext->getShippingLocation(),
                $salesChannelContext->getPaymentMethod(),
                $salesChannelContext->getCustomer(),
                $method
            );

            $cart = $this->cartService->recalculate($cart, $salesChannelWithCurrentShippingMethod);
            /** @var Delivery $delivery */
            $delivery = $cart->getDeliveries()->first();
            $price = $delivery ? $delivery->getShippingCosts()->getTotalPrice() : 0;
            $value = $this->currencyUtil->sanitize($price, $currency);

            return [
                'id' => $method->getId(),
                'label' => $method->getTranslation('name') ?? '',
                'description' => $method->getTranslation('description') ?? '',
                'value' => $value,
                'currency' => $currency,
                'selected' => $selectedShippingMethod->getId() === $method->getId(),
            ];
        }, $shippingMethods->getElements());

        $this->createContext(
            $salesChannelContext,
            $salesChannelContext->getToken(),
            $salesChannelContext->getShippingLocation(),
            $salesChannelContext->getPaymentMethod(),
            $salesChannelContext->getCustomer(),
            $selectedShippingMethod
        );

        return $availableShippingMethods;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    private function setShippingInContext(SalesChannelContext $salesChannelContext): void
    {
        $this->contextPersister->save(
            $salesChannelContext->getToken(),
            [
                SalesChannelContextService::SHIPPING_METHOD_ID => $salesChannelContext->getShippingMethod()->getId()
            ],
            $salesChannelContext->getSalesChannel()->getId()
        );
    }
}
