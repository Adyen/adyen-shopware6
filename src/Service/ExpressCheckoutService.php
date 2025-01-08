<?php

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Service\Repository\ExpressCheckoutRepository;
use Adyen\Shopware\Exception\ResolveShippingMethodException;
use Adyen\Shopware\Util\Currency;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
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
     * @var bool
     */
    private bool $isVersion64;

    /**
     * @var bool
     */
    private bool $isLoggedIn;

    public function __construct(
        CartService                 $cartService,
        ExpressCheckoutRepository   $expressCheckoutRepository,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        Currency                    $currencyUtil,
        KernelInterface             $kernel
    ) {
        $this->cartService = $cartService;
        $this->expressCheckoutRepository = $expressCheckoutRepository;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
        $this->currencyUtil = $currencyUtil;
        $this->isVersion64 = $this->isVersion64($kernel->getContainer()->getParameter('kernel.shopware_version'));
    }

    /**
     * Checks if the given version string corresponds to Shopware 6.4.
     *
     * @param string $version The version string to check, e.g., "6.4.20.2".
     *
     * @return bool True if the version is 6.4, false otherwise.
     */
    private function isVersion64(string $version): bool
    {
        $parts = explode('.', $version);
        return $parts[0] === '6' && $parts[1] === '4';
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
    public function getExpressCheckoutConfigOnProductPage(
        string $productId,
        int $quantity,
        SalesChannelContext $salesChannelContext,
        array               $newAddress = [],
        array               $newShipping = [],
        string $formattedHandlerIdentifier = ''
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

        $cart = $cartData['cart'];

        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amountInMinorUnits = $this->currencyUtil->sanitize($cart->getPrice()->getTotalPrice(), $currency);

        // Available shipping methods for the given address
        $shippingMethods = array_map(function (ShippingMethodEntity $method) {
            return [
                'id' => $method->getId(),
                'label' => $method->getName(),
                'description' => $method->getDescription()
            ];
        }, $cartData['shippingMethods']->getElements());

        // Available payment methods
        $paymentMethods = $cartData['paymentMethods'];

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
        string $formattedHandlerIdentifier = ''
    ): array {
        // Check if the user is guest or customer
        $this->isLoggedIn = $salesChannelContext->getCustomer() !== null;

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

        // Resolving shipping location
        $country = $this->expressCheckoutRepository->resolveCountry($salesChannelContext, $newAddress);
        $shippingLocation = ShippingLocation::createFromCountry($country);

        // Check Shopware version and create context accordingly
        $updatedSalesChannelContext = $this->isVersion64
            ? $this->createContextFor64($salesChannelContext, $token, $shippingLocation, $paymentMethod)
            : $this->createContextFor65($salesChannelContext, $token, $shippingLocation, $paymentMethod);

        // recalculate the cart
        $cart = $this->cartService->recalculate($cart, $updatedSalesChannelContext);

        // Fetch available shipping methods
        $shippingMethods = $this->expressCheckoutRepository
            ->fetchAvailableShippingMethods($updatedSalesChannelContext, $cart);

        // Fetch shipping method
        $shippingMethod = $this->resolveShippingMethod($updatedSalesChannelContext, $cart, $newShipping);

        // Recreate context with selected shipping method
        $updatedSalesChannelContext = $this->isVersion64
            ? $this->createContextFor64(
                $salesChannelContext,
                $token,
                $shippingLocation,
                $paymentMethod,
                $shippingMethod
            )
            : $this->createContextFor65(
                $salesChannelContext,
                $token,
                $shippingLocation,
                $paymentMethod,
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
        ];
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
        SalesChannelContext $salesChannelContext,
        string $token,
        ShippingLocation $shippingLocation,
        PaymentMethodEntity $paymentMethod,
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
            $salesChannelContext->getCustomer(),
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
        SalesChannelContext $salesChannelContext,
        string $token,
        ShippingLocation $shippingLocation,
        PaymentMethodEntity $paymentMethod,
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
            $salesChannelContext->getCustomer(),
            $salesChannelContext->getItemRounding(),
            $salesChannelContext->getTotalRounding()
        );
    }
}
