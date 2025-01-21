<?php

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\DeliveryMethod;
use Adyen\Model\Checkout\PaypalUpdateOrderRequest;
use Adyen\Model\Checkout\PaypalUpdateOrderResponse;
use Adyen\Service\Checkout\UtilityApi;
use Adyen\Shopware\Service\Repository\ExpressCheckoutRepository;
use Adyen\Shopware\Exception\ResolveShippingMethodException;
use Adyen\Shopware\Util\Currency;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
     * @var string
     */
    private string $shopwareVersion;

    public function __construct(
        CartService                 $cartService,
        ExpressCheckoutRepository   $expressCheckoutRepository,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        ClientService               $clientService,
        Currency                    $currencyUtil,
        string                      $shopwareVersion
    ) {
        $this->cartService = $cartService;
        $this->expressCheckoutRepository = $expressCheckoutRepository;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->clientService = $clientService;
        $this->currencyUtil = $currencyUtil;
        $this->shopwareVersion = $shopwareVersion;
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
        $shippingMethods = array_map(function (ShippingMethodEntity $method) use ($currency, $cartData) {
            /** @var ShippingMethodEntity $shippingMethod */
            $shippingMethod = $cartData['shippingMethod'];

            /** @var ShippingMethodPriceEntity $shippingMethodPriceEntity */
            $shippingMethodPriceEntity = $method->getPrices()->first();

            /** @var null|Price $price */
            $price = null;
            if ($shippingMethodPriceEntity && $shippingMethodPriceEntity->getCurrencyPrice()) {
                $price = $shippingMethodPriceEntity->getCurrencyPrice()->first();
            }

            $value = 0;
            if ($price) {
                $value = $this->currencyUtil->sanitize($price->getGross(), $currency);
            }

            return [
                'id' => $method->getId(),
                'label' => $method->getName(),
                'description' => $method->getDescription() ?? '',
                'value' => $value,
                'currency' => $currency,
                'selected' => $shippingMethod->getId() === $method->getId(),
            ];
        }, $cartData['shippingMethods']->getElements());

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
     * @throws \Exception
     */
    public function createCart(
        string              $productId,
        int                 $quantity,
        SalesChannelContext $salesChannelContext,
        array               $newAddress = [],
        array               $newShipping = [],
        string              $formattedHandlerIdentifier = '',
        string              $guestEmail = '',
        bool                $makeNewCustomer = false
    ): array {
        $newCustomer = $salesChannelContext->getCustomer();

        // Check if the user is guest or customer
        $isLoggedIn = $newCustomer !== null;

        $token = $salesChannelContext->getToken();
        $cart = $this->cartService->getCart($token, $salesChannelContext);

        if ($productId !== "-1") { // product page
            // Creating new cart with the product from the product page
            $lineItem = new LineItem($productId, 'product', $productId, $quantity);
            $cart = $this->cartService->createNew($tokenNew = Uuid::randomHex());
            $cart->add($lineItem);
            $token = $tokenNew;
        }

        // Get payment method
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        if ($formattedHandlerIdentifier !== '') {
            // Express checkout payment method
            $paymentMethod = $this->paymentMethodsFilterService
                ->getPaymentMethodByFormattedHandler($formattedHandlerIdentifier, $salesChannelContext->getContext());
        }

        $shippingLocation = $salesChannelContext->getShippingLocation();

        // Resolving shipping location for guest
        if (!$isLoggedIn) {
            $country = $this->expressCheckoutRepository->resolveCountry($salesChannelContext, $newAddress);
            $shippingLocation = ShippingLocation::createFromCountry($country);
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
     * @throws \Exception If the customer cannot be found.
     *
     * @return SalesChannelContext The updated SalesChannelContext with the customer's details.
     */
    public function changeContext(string $customerId, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        // Fetch the customer by ID
        $customer = $this->expressCheckoutRepository->findCustomerById($customerId, $salesChannelContext);

        // Update the remote address
        $customer->setRemoteAddress($_SERVER['REMOTE_ADDR']);

        // Create the shipping location from the customer's billing address
        $shippingLocation = ShippingLocation::createFromAddress($customer->getDefaultBillingAddress());

        // Update the context
        return $this->createContext(
            $salesChannelContext,
            $salesChannelContext->getToken(),
            $shippingLocation,
            $salesChannelContext->getPaymentMethod(),
            $customer
        );
    }

    /**
     * @param array $data
     * @param array $shippingMethods
     * @param SalesChannelContext $salesChannelContext
     * @return PaypalUpdateOrderResponse
     * @throws AdyenException
     */
    public function paypalUpdateOrder(
        array               $data,
        array               $shippingMethods,
        SalesChannelContext $salesChannelContext
    ): PaypalUpdateOrderResponse {
        $utilityApiService = new UtilityApi(
            $this->clientService->getClient($salesChannelContext->getSalesChannel()->getId())
        );

        $paypalUpdateOrderRequest = new PaypalUpdateOrderRequest($data);
        $deliveryMethods = [];
        foreach ($shippingMethods as $shippingMethod) {
            $deliveryMethods[] = new DeliveryMethod(
                [
                    'amount' => new Amount($shippingMethod),
                    'description' => $shippingMethod['label'],
                    'reference' => $shippingMethod['id'],
                    'selected' => $shippingMethod['selected'],
                    'type' => $shippingMethod['type'],
                ]
            );
        }

        $paypalUpdateOrderRequest->setDeliveryMethods($deliveryMethods);

        return $utilityApiService
            ->updatesOrderForPaypalExpressCheckout($paypalUpdateOrderRequest);
    }

    /**
     * Resolves the shipping method based on the provided cart and new shipping details.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param Cart $cart The cart to calculate shipping for.
     * @param array $newShipping Optional new shipping method details.
     * @return ShippingMethodEntity The resolved shipping method.
     * @throws \Exception If no valid shipping method is available.
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
     * Creates a SalesChannelContext based on the Shopware version.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param string $token The token to be associated with the new context.
     * @param ShippingLocation $shippingLocation The shipping location to be used.
     * @param PaymentMethodEntity $paymentMethod The payment method entity to set in the context.
     * @param CustomerEntity|null $customer The customer entity (optional).
     * @param ShippingMethodEntity|null $shippingMethod The optional shipping method entity to set in the context.
     *
     * @return SalesChannelContext The created SalesChannelContext.
     * @throws \Exception If the Shopware version is unsupported.
     */
    public function createContext(
        SalesChannelContext   $salesChannelContext,
        string                $token,
        ShippingLocation      $shippingLocation,
        PaymentMethodEntity   $paymentMethod,
        ?CustomerEntity       $customer = null,
        ?ShippingMethodEntity $shippingMethod = null
    ): SalesChannelContext {
        if (str_starts_with($this->shopwareVersion, '6.4')) {
            return $this->createContextFor64(
                $salesChannelContext,
                $token,
                $shippingLocation,
                $paymentMethod,
                $customer,
                $shippingMethod
            );
        }

        if (str_starts_with($this->shopwareVersion, '6.5')) {
            return $this->createContextFor65(
                $salesChannelContext,
                $token,
                $shippingLocation,
                $paymentMethod,
                $shippingMethod
            );
        }

        throw new \Exception(sprintf('Unsupported Shopware version: %s', $this->shopwareVersion));
    }

    /**
     * Creates a SalesChannelContext for Shopware 6.4.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param string $token The token to be associated with the new context.
     * @param ShippingLocation $shippingLocation The shipping location to be used.
     * @param PaymentMethodEntity $paymentMethod The payment method entity to set in the context.
     * @param ShippingMethodEntity|null $shippingMethod The optional shipping method entity to set in the context.
     *
     * @return SalesChannelContext A new SalesChannelContext for Shopware 6.4.
     */
    private function createContextFor64(
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
     * Creates a SalesChannelContext for Shopware 6.5.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param string $token The token to be associated with the new context.
     * @param ShippingLocation $shippingLocation The shipping location to be used.
     * @param PaymentMethodEntity $paymentMethod The payment method entity to set in the context.
     * @param ShippingMethodEntity|null $shippingMethod The optional shipping method entity to set in the context.
     *
     * @return SalesChannelContext A new SalesChannelContext for Shopware 6.5.
     */
    private function createContextFor65(
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
}
