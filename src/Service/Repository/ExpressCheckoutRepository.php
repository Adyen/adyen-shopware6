<?php

namespace Adyen\Shopware\Service\Repository;

use Adyen\Shopware\Exception\ResolveCountryException;
use Exception;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ExpressCheckoutRepository
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $shippingMethodRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $customerRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $countryStateRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $salutationRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $countryRepository;

    public function __construct(
        EntityRepository $shippingMethodRepository,
        EntityRepository $customerRepository,
        EntityRepository $countryStateRepository,
        EntityRepository $salutationRepository,
        EntityRepository $countryRepository
    ) {
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->customerRepository = $customerRepository;
        $this->countryStateRepository = $countryStateRepository;
        $this->salutationRepository = $salutationRepository;
        $this->countryRepository = $countryRepository;
    }

    /**
     * Fetches the available shipping methods for the given context and cart.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param Cart $cart The cart to calculate shipping for.
     * @return ShippingMethodCollection The collection of available shipping methods.
     */
    public function fetchAvailableShippingMethods(
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
     * Resolves the country entity based on the provided new address or context.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @return CountryEntity The resolved country entity.
     * @throws Exception If the country cannot be resolved.
     */
    public function resolveCountry(SalesChannelContext $salesChannelContext, array $newAddress = []): CountryEntity
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
            throw new ResolveCountryException('Invalid country information.');
        }

        return $country;
    }

    /**
     * Retrieves the country ID for the given ISO code.
     * @param string $isoCode The ISO code of the country (e.g., 'US', 'DE').
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     *
     * @throws \Exception If the country could not be found.
     *
     * @return string The ID of the country.
     */
    public function getCountryId(string $isoCode, SalesChannelContext $salesChannelContext): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $isoCode));

        $country = $this->countryRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$country) {
            throw new \Exception(sprintf('Country with ISO code "%s" not found.', $isoCode));
        }

        return $country->getId();
    }

    /**
     * Retrieves the salutation ID for the 'undefined' salutation key.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @throws \Exception If the salutation could not be found.
     * @return string The ID of the salutation.
     */
    public function getSalutationId(SalesChannelContext $salesChannelContext): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));

        $salutation = $this->salutationRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$salutation) {
            throw new \Exception(sprintf('Salutation with key undefined not found.'));
        }

        return $salutation->getId();
    }

    /**
     * Retrieves the state ID for the given administrative area and country code (e.g., 'US-CA').
     *
     * @param string $administrativeArea The administrative area code (e.g., 'NY', 'CA').
     * @param string $countryCode The ISO code of the country (e.g., 'US', 'DE').
     * @param SalesChannelContext $salesChannelContext The sales channel context for repository operations.
     *
     * @return string|null The ID of the state if found, or null if not found.
     */
    public function getStateId(string $administrativeArea, string $countryCode, SalesChannelContext $salesChannelContext): ?string
    {
        $shortCode = $countryCode . '-' . $administrativeArea;

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shortCode', $shortCode));
        $state = $this->countryStateRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$state) {
            throw new \Exception(sprintf('State with country code "%s" and administrative area "%s" not found.', $countryCode, $administrativeArea));
        }

        return $state->getId();
    }

    /**
     * Retrieves a customer by ID with necessary associations.
     *
     * @param string $customerId The ID of the customer to retrieve.
     * @param SalesChannelContext $salesChannelContext The sales channel context for the query.
     *
     * @throws \Exception If the customer is not found.
     *
     * @return CustomerEntity The customer entity.
     */
    public function findCustomerById(string $customerId, SalesChannelContext $salesChannelContext): CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultShippingAddress.country');

        $customer = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$customer) {
            throw new \Exception(sprintf('Customer with id "%s" not found.', $customerId));
        }

        return $customer;
    }

    /**
     * Creates a guest customer with a default billing and shipping address.
     * @param SalesChannelContext $salesChannelContext The sales channel context containing customer group and payment method details.
     * @param string $guestEmail The email address for the guest customer.
     * @param array $newAddress The address details for the customer
     * @throws \Exception If the customer could not be found after creation.
     * @return CustomerEntity The created guest customer entity.
     */
    public function createGuestCustomer(SalesChannelContext $salesChannelContext, string $guestEmail, array $newAddress): CustomerEntity
    {
        // Guest data
        $customerId = Uuid::randomHex();
        $firstName = 'Guest';
        $lastName = 'Guest';
        $guest = true;
        $email = $guestEmail;
        //$salutationId = '939ce876c042403793ce9e39706ed266'; // TO DO
        $salutationId = $this->getSalutationId($salesChannelContext);
        $password = null;
        $groupId = $salesChannelContext->getCurrentCustomerGroup()->getId();
        $paymentMethodId = $salesChannelContext->getPaymentMethod()->getId();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $customerNumber = Uuid::randomHex();

        // Address data
        $addressId = Uuid::randomHex();
        //$countryId = 'cca78a472edb484f9ddf9b51432f8948'; // TO DO
        $countryId = $this->getCountryId($newAddress['countryCode'], $salesChannelContext);
        //$stateID = $this->getStateId('CA', 'US', $salesChannelContext); // TO DO
        $stateID = null;
        if (isset($newAddress['administrativeArea'], $newAddress['countryCode'])) {
            $stateID = $this->getStateId($newAddress['administrativeArea'], $newAddress['countryCode'], $salesChannelContext);
        }
        $city =  $newAddress['locality'];
        $street = $newAddress['address1'];
        $zipcode = $newAddress['postalCode'];
        $additionalAddressLine1 = $newAddress['address2'];
        $additionalAddressLine2 = $newAddress['address3'];

        $customerData = [
            [
                'id' => $customerId,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'guest' => $guest,
                'email' => $email,
                'salutationId' => $salutationId,
                'password' => $password,
                'groupId' => $groupId,
                'defaultPaymentMethodId' => $paymentMethodId,
                'salesChannelId' => $salesChannelId,
                'customerNumber' => $customerNumber,
                'defaultBillingAddress' => [
                    'id' => $addressId,
                    'countryId' => $countryId,
                    'countryStateId' => $stateID,
                    'city' => $city,
                    'street' => $street,
                    'additionalAddressLine1' => $additionalAddressLine1,
                    'additionalAddressLine2' => $additionalAddressLine2,
                    'zipcode' => $zipcode,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'salutationId' => $salutationId,
                ],
                'defaultShippingAddress' => [
                    'id' => $addressId,
                    'countryId' => $countryId,
                    'countryStateId' => $stateID,
                    'city' => $city,
                    'street' => $street,
                    'additionalAddressLine1' => $additionalAddressLine1,
                    'additionalAddressLine2' => $additionalAddressLine2,
                    'zipcode' => $zipcode,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'salutationId' => $salutationId,
                ],
            ],
        ];

        // Create Guest customer
        $this->customerRepository->create($customerData, $salesChannelContext->getContext());

        // Get user by id
        $criteria = new Criteria();
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultBillingAddress.countryState');
        $criteria->addFilter(new EqualsFilter('id', $customerId));

        $customer = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$customer) {
            throw new \Exception('Customer not found');
        }

        return $customer;
    }
}
