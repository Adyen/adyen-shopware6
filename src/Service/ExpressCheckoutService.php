<?php

namespace Adyen\Shopware\Service;

use Adyen\Model\Checkout\PaymentMethodsResponse;
use Adyen\Shopware\Exception\ResolveCountryException;
use Adyen\Shopware\Exception\ResolveShippingMethodException;
use Adyen\Shopware\Util\Currency;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\KernelInterface;

class ExpressCheckoutService
{

    /** @var CartService */
    private $cartService;

    /**
     * @var EntityRepository
     */
    private $countryRepository;
    /**
     * @var EntityRepository
     */
    private $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    private $shippingMethodRepository;

    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * @var PaymentMethodsFilterService
     */
    private $paymentMethodsFilterService;

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
        CartService           $cartService,
        EntityRepository      $countryRepository,
        EntityRepository      $paymentMethodRepository,
        EntityRepository      $shippingMethodRepository,
        PaymentMethodsService $paymentMethodsService,
        PaymentMethodsFilterService $paymentMethodsFilterService,
        Currency $currencyUtil,
        KernelInterface $kernel
    ) {
        $this->cartService = $cartService;
        $this->countryRepository = $countryRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->paymentMethodsService = $paymentMethodsService;
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
        array               $newShipping = []
    ): array {
        // Creating new cart
        $cartData = $this->createCart($productId, $quantity, $salesChannelContext, $newAddress, $newShipping);

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

        $this->getAvailableExpressCheckoutPaymentMethods($salesChannelContext);

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
        if ($customer && $customer->getActiveShippingAddress() && $customer->getActiveShippingAddress()->getCountry()) {
            return $customer->getActiveShippingAddress()->getCountry()->getIso();
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $salesChannelContext->getSalesChannel()->getCountryId()));
        /** @var null|CountryEntity $country */
        $country = $this->countryRepository->search($criteria, $salesChannelContext->getContext())->first();
        if ($country) {
            return $country->getIso();
        }

        return '';
    }

    /**
     * Returns filtered Adyen express checkout payment methods
     *
     * @param SalesChannelContext $salesChannelContext
     * @return PaymentMethodsResponse
     */
    public function getAvailableExpressCheckoutPaymentMethods(
        SalesChannelContext $salesChannelContext
    ): PaymentMethodsResponse {
        $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext)
            ->getPaymentMethods();
        $salesChannelPaymentMethodIs = $shopwarePaymentMethods = $salesChannelContext->getSalesChannel()
            ->getPaymentMethodIds();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $salesChannelPaymentMethodIs));
        /** @var null|PaymentMethodCollection $country */
        $paymentMethods = $this->paymentMethodRepository->search($criteria, $salesChannelContext->getContext())
            ->getEntities();

        return new PaymentMethodsResponse();
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
        array               $newShipping = []
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

        // Resolving shipping location
        $country = $this->resolveCountry($salesChannelContext, $newAddress);
        $shippingLocation = ShippingLocation::createFromCountry($country);

        // Check Shopware version and create context accordingly
        $updatedSalesChannelContext = $this->isVersion64
            ? $this->createContextFor64($salesChannelContext, $token, $shippingLocation)
            : $this->createContextFor65($salesChannelContext, $token, $shippingLocation);

        // recalculate the cart
        $cart = $this->cartService->recalculate($cart, $updatedSalesChannelContext);

        // Fetch available shipping methods
        $shippingMethods = $this->fetchAvailableShippingMethods($updatedSalesChannelContext, $cart);

        // Fetch shipping method
        $shippingMethod = $this->resolveShippingMethod($updatedSalesChannelContext, $cart, $newShipping);

        // Recreate context with selected shipping method
        $updatedSalesChannelContext = $this->isVersion64
            ? $this->createContextFor64($salesChannelContext, $token, $shippingLocation, $shippingMethod)
            : $this->createContextFor65($salesChannelContext, $token, $shippingLocation, $shippingMethod);

        // Recalculate the cart
        $cart = $this->cartService->recalculate($cart, $updatedSalesChannelContext);

        $paymentMethods = $this->paymentMethodsService->getPaymentMethods($updatedSalesChannelContext);
        $filteredPaymentMethods = $this->paymentMethodsFilterService
            ->filterAndValidatePaymentMethods($paymentMethods, $cart, $salesChannelContext);

        return [
            'cart' => $cart,
            'shippingMethods' => $shippingMethods,
            'shippingMethod' => $shippingMethod,
            'shippingLocation' => $shippingLocation,
            'paymentMethods' => $filteredPaymentMethods,
        ];
    }

    /**
     * Resolves the country entity based on the provided new address or context.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @return CountryEntity The resolved country entity.
     * @throws \Exception If the country cannot be resolved.
     */
    private function resolveCountry(SalesChannelContext $salesChannelContext, array $newAddress = []): CountryEntity
    {
        $criteria = new Criteria();

        // If country code is present in request, use it to find the country
        if (!empty($newAddress['countryCode'])) {
            $criteria->addFilter(new EqualsFilter('iso', $newAddress['countryCode']));
        } else {
            // If user is logged in, use its shipping country
            // And if not, use shop default country
            $customer = $salesChannelContext->getCustomer();
            $countryIso = $this->getCountryCode($customer, $salesChannelContext);
            $criteria->addFilter(new EqualsFilter('iso', $countryIso));
        }

        $country = $this->countryRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$country) {
            throw new ResolveCountryException("No shipping country found!");
        }

        return $country;
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
        $filteredMethods = $this->fetchAvailableShippingMethods($salesChannelContext, $cart);

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
     * Fetches the available shipping methods for the given context and cart.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param Cart $cart The cart to calculate shipping for.
     * @return ShippingMethodCollection The collection of available shipping methods.
     */
    private function fetchAvailableShippingMethods(
        SalesChannelContext $salesChannelContext,
        Cart $cart
    ): ShippingMethodCollection {
        $criteria = new Criteria();
        $criteria->addAssociation('availabilityRule');

        /** @var ShippingMethodCollection $shippingMethods */
        $shippingMethods = $this->shippingMethodRepository->search($criteria, $salesChannelContext->getContext())
            ->getEntities();

        return $shippingMethods
            ->filter(function (ShippingMethodEntity $shippingMethod) use ($cart, $salesChannelContext) {
                $availabilityRule = $shippingMethod->getAvailabilityRule();
                return !$availabilityRule || $availabilityRule->getPayload()
                    ->match(new CartRuleScope($cart, $salesChannelContext));
            });
    }

    /**
     * Creates a SalesChannelContext for Shopware 6.4.
     */
    private function createContextFor64(
        SalesChannelContext $salesChannelContext,
        string $token,
        ShippingLocation $shippingLocation,
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
            $salesChannelContext->getPaymentMethod(),
            $shippingMethod ?? $salesChannelContext->getShippingMethod(),
            $shippingLocation,
            $salesChannelContext->getCustomer(),
            $salesChannelContext->getItemRounding(),
            $salesChannelContext->getTotalRounding()
        );
    }

    /**
     * Creates a SalesChannelContext for Shopware 6.5.
     */
    private function createContextFor65(
        SalesChannelContext $salesChannelContext,
        string $token,
        ShippingLocation $shippingLocation,
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
            $salesChannelContext->getPaymentMethod(),
            $shippingMethod ?? $salesChannelContext->getShippingMethod(),
            $shippingLocation,
            $salesChannelContext->getCustomer(),
            $salesChannelContext->getItemRounding(),
            $salesChannelContext->getTotalRounding()
        );
    }
}
