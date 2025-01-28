<?php

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
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Api\Controller\ApiController;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

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

    /** @var ApiController */
    private ApiController $apiController;

    /** @var OrderConverter */
    private OrderConverter $orderConverter;

    public function __construct(
        CartService                  $cartService,
        ExpressCheckoutRepository    $expressCheckoutRepository,
        PaymentMethodsFilterService  $paymentMethodsFilterService,
        ClientService                $clientService,
        Currency                     $currencyUtil,
        SalesChannelContextPersister $contextPersister,
        ApiController                $apiController,
        OrderConverter               $orderConverter
    ) {
        $this->cartService = $cartService;
        $this->expressCheckoutRepository = $expressCheckoutRepository;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->clientService = $clientService;
        $this->currencyUtil = $currencyUtil;
        $this->contextPersister = $contextPersister;
        $this->apiController = $apiController;
        $this->orderConverter = $orderConverter;
    }

    /**
     * Retrieves the express checkout configuration on the product page.
     *
     * @param string $productId The ID of the product.
     * @param int $quantity The quantity of the product.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @param array $newShipping Optional new shipping method details.
     * @return array The configuration for express checkout.
     * @throws Exception
     */
    public function getExpressCheckoutConfig(
        string              $productId,
        int                 $quantity,
        SalesChannelContext $salesChannelContext,
        array               $newAddress = [],
        array               $newShipping = [],
        string              $formattedHandlerIdentifier = ''
    ): array {
        // Creating new cart
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
        $shippingMethods = $this->getAvailableShippingMethods($cartData, $currency);

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
    }

    /**
     *
     * Retrieves the customer's active address if the customer exists,
     * otherwise, returns the default country of the sales channel.
     *
     * @param CustomerEntity|null $customer
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function getCountryCode(?CustomerEntity $customer, SalesChannelContext $salesChannelContext): string
    {
        return $this->expressCheckoutRepository->getCountryCode($customer, $salesChannelContext);
    }

    /**
     * Creates a cart with the provided product and calculates it with the resolved shipping location and method.
     *
     * @param string $productId The ID of the product.
     * @param int $quantity The quantity of the product.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @param array $newShipping Optional new shipping method details.
     * @return array The cart, shipping methods, selected shipping method, and payment methods.
     * @throws ResolveCountryException|ResolveShippingMethodException
     */
    public function createCart(
        string              $productId,
        int                 $quantity,
        SalesChannelContext $salesChannelContext,
        array               $newAddress = [],
        array               $newShipping = [],
        string              $formattedHandlerIdentifier = '',
        string              $guestEmail = '',
        bool                $makeNewCustomer = false,
        OrderEntity         $order = null
    ): array {
        $newCustomer = $salesChannelContext->getCustomer();

        // Check if the user is guest or customer
        $isLoggedIn = $newCustomer && !$newCustomer->getGuest();

        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);

        if ($productId !== "-1") { // product page
            // Creating new cart with the product from the product page
            $lineItem = new LineItem($productId, 'product', $productId, $quantity);
            $cart = $this->cartService->createNew($tokenNew = Uuid::randomHex());
            $cart->add($lineItem);
            $token = $tokenNew;
        }

        // If order already exists for PayPal payments
        if ($order) {
            $cart = $this->cartService->createNew($tokenNew = Uuid::randomHex());
            $token = $tokenNew;

            $orderLineItems = $order->getLineItems();
            foreach ($orderLineItems as $orderLineItem) {
                $lineItem = new LineItem(
                    $orderLineItem->getProductId(),
                    'product',
                    $orderLineItem->getProductId(),
                    $orderLineItem->getQuantity()
                );
                $cart->add($lineItem);
            }

            if ($newAddress) {
                $this->expressCheckoutRepository->resolveCountry($salesChannelContext, $newAddress);
                $address = $this->expressCheckoutRepository->updateOrderAddressAndCustomer(
                    $newAddress,
                    $newCustomer,
                    $order->getBillingAddressId(),
                    $order->getOrderCustomer() ? $order->getOrderCustomer()->getId() : '',
                    $salesChannelContext
                );

                $shippingLocation = ShippingLocation::createFromAddress($address);
            }
        }

        // Get payment method
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        if ($formattedHandlerIdentifier !== '') {
            // Express checkout payment method
            $paymentMethod = $this->paymentMethodsFilterService
                ->getPaymentMethodByFormattedHandler($formattedHandlerIdentifier, $salesChannelContext->getContext());
        }

        $shippingLocation = $shippingLocation ?? $salesChannelContext->getShippingLocation();

        // Resolving shipping location for guest
        if (!$isLoggedIn) {
            $country = $this->expressCheckoutRepository->resolveCountry($salesChannelContext, $newAddress);
            $shippingLocation = ShippingLocation::createFromCountry($country);

            if ($newCustomer) {
                $shippingLocation = ShippingLocation::createFromAddress($newCustomer->getDefaultBillingAddress());
            }
        }

        if ($makeNewCustomer) {
            $newCustomer = $this->expressCheckoutRepository->createGuestCustomer(
                $salesChannelContext,
                $guestEmail,
                $newAddress
            );
            $shippingLocation = ShippingLocation::createFromAddress($newCustomer->getDefaultBillingAddress());
        }

        // Create updated context
        $updatedSalesChannelContext = $this->createContext(
            $salesChannelContext,
            $token,
            $shippingLocation,
            $paymentMethod,
            $newCustomer
        );

        // recalculate the cart
        $cart = $this->cartService->recalculate($cart, $updatedSalesChannelContext);

        // Fetch available shipping methods
        $shippingMethods = $this->expressCheckoutRepository
            ->fetchAvailableShippingMethods($updatedSalesChannelContext, $cart);

        // Fetch shipping method
        $shippingMethod = $this->resolveShippingMethod($updatedSalesChannelContext, $cart, $newShipping);

        // Recreate context with selected shipping method
        $updatedSalesChannelContext = $this->createContext(
            $salesChannelContext,
            $token,
            $shippingLocation,
            $paymentMethod,
            $newCustomer,
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
            'customerId' => $newCustomer ? $newCustomer->getId() : '',
        ];
    }

    /**
     * Updates the SalesChannelContext for guest customer.
     *
     * @param string $customerId The ID of the customer whose context should be updated.
     * @param SalesChannelContext $salesChannelContext The existing sales channel context to be updated.
     *
     * @return SalesChannelContext The updated SalesChannelContext with the customer's details.
     * @throws Exception If the customer cannot be found.
     *
     */
    public function changeContext(string $customerId, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        // Fetch the customer by ID
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
            [SalesChannelContextService::CUSTOMER_ID => $customerId],
            $salesChannelContext->getSalesChannel()->getId()
        );

        return $salesChannelContext;
    }

    /**
     * @param string $orderId
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     * @param array $newAddress
     * @param array $newShipping
     * @return PaypalUpdateOrderResponse
     * @throws AdyenException|ResolveCountryException|ResolveShippingMethodException
     */
    public function paypalUpdateOrder(
        string              $orderId,
        array               $data,
        SalesChannelContext $salesChannelContext,
        array               $newAddress = [],
        array               $newShipping = []
    ): PaypalUpdateOrderResponse {
        /** @var OrderEntity $order */
        $order = $this->expressCheckoutRepository->getOrderById($orderId, $salesChannelContext->getContext());
        $cartData = $this->createCart(
            '-1',
            -1,
            $salesChannelContext,
            $newAddress,
            $newShipping,
            'handler_adyen_paypalpaymentmethodhandler',
            '',
            false,
            $order
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
        $shippingMethods = $this->getAvailableShippingMethods($cartData, $currency);
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

        $this->cartService->deleteCart($cartData['updatedSalesChannelContext']);

        return $utilityApiService
            ->updatesOrderForPaypalExpressCheckout($paypalUpdateOrderRequest);
    }

    /**
     * @param Request $request
     * @param string $orderId
     * @param SalesChannelContext $salesChannelContext
     * @param array $newAddress
     * @param array $newShipping
     * @return void
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     */
    public function updateShopOrder(
        Request             $request,
        string              $orderId,
        SalesChannelContext $salesChannelContext,
        array               $newAddress = [],
        array               $newShipping = []
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
            $order
        );
        /** @var SalesChannelContext $updatedSalesChannelContext */
        $updatedSalesChannelContext = $cartData['updatedSalesChannelContext'];

        $versionId = json_decode($this->apiController->createVersion(
            $request,
            $updatedSalesChannelContext->getContext(),
            'order',
            $orderId
        )->getContent(), true)['versionId'];

        $contextWithNewVersion = $updatedSalesChannelContext->getContext()->createWithVersionId($versionId);
        /** @var OrderEntity $orderWithNewVersion */
        $orderWithNewVersion = $this->expressCheckoutRepository->getOrderById(
            $orderId,
            $contextWithNewVersion
        );
        $cartFromOrder = $this->orderConverter->convertToCart($orderWithNewVersion, $contextWithNewVersion);
        $recalculatedCart = $this->cartService->recalculate($cartFromOrder, $updatedSalesChannelContext);

        $newOrderData = $this->orderConverter->convertToOrder(
            $recalculatedCart,
            $updatedSalesChannelContext,
            new OrderConversionContext()
        );
        $newOrderData['id'] = $order->getId();

        $this->expressCheckoutRepository->upsertOrder($newOrderData, $contextWithNewVersion);
        $this->apiController->mergeVersion($contextWithNewVersion, 'order', $versionId);

        $this->cartService->deleteCart($updatedSalesChannelContext);
    }

    /**
     * Resolves the shipping method based on the provided cart and new shipping details.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param Cart $cart The cart to calculate shipping for.
     * @param array $newShipping Optional new shipping method details.
     * @return ShippingMethodEntity The resolved shipping method.
     * @throws ResolveShippingMethodException
     */
    private function resolveShippingMethod(
        SalesChannelContext $salesChannelContext,
        Cart                $cart,
        array               $newShipping
    ): ShippingMethodEntity {
        // Fetch available shipping methods
        $filteredMethods = $this->expressCheckoutRepository->fetchAvailableShippingMethods($salesChannelContext, $cart);

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
        SalesChannelContext   $salesChannelContext,
        string                $token,
        ShippingLocation      $shippingLocation,
        PaymentMethodEntity   $paymentMethod,
        ?CustomerEntity       $customer = null,
        ?ShippingMethodEntity $shippingMethod = null
    ): SalesChannelContext {
        return new SalesChannelContext(
            $salesChannelContext->getContext(),
            $token,
            $options[SalesChannelContextService::DOMAIN_ID] ?? null,
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

    private function getAvailableShippingMethods(array $cartData, string $currency): array
    {
        /** @var ShippingMethodEntity $selectedShippingMethod */
        $selectedShippingMethod =  $cartData['shippingMethod'];
        /** @var Cart $cart */
        $cart = $cartData['cart'];
        /** @var SalesChannelContext $salesChannelContext */
        $salesChannelContext = $cartData['updatedSalesChannelContext'];

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
            $delivery =$cart->getDeliveries()->first();
            $price = $delivery->getShippingCosts()->getTotalPrice();
            $value = $this->currencyUtil->sanitize($price, $currency);

            return [
                'id' => $method->getId(),
                'label' => $method->getName(),
                'description' => $method->getDescription() ?? '',
                'value' => $value,
                'currency' => $currency,
                'selected' => $selectedShippingMethod->getId() === $method->getId(),
            ];
        }, $cartData['shippingMethods']->getElements());

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
}
