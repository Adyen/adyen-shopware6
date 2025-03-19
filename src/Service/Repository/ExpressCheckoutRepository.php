<?php

namespace Adyen\Shopware\Service\Repository;

use Adyen\Shopware\Exception\ResolveCountryException;
use Exception;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
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

    /**
     * @var EntityRepository
     */
    private EntityRepository $orderRepository;

    /** @var EntityRepository */
    private EntityRepository $orderAddressRepository;

    /** @var EntityRepository */
    private EntityRepository $customerAddressRepository;

    /** @var EntityRepository */
    private EntityRepository $orderCustomerRepository;

    public function __construct(
        EntityRepository $shippingMethodRepository,
        EntityRepository $customerRepository,
        EntityRepository $countryStateRepository,
        EntityRepository $salutationRepository,
        EntityRepository $countryRepository,
        EntityRepository $orderRepository,
        EntityRepository $orderAddressRepository,
        EntityRepository $customerAddressRepository,
        EntityRepository $orderCustomerRepository
    ) {
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->customerRepository = $customerRepository;
        $this->countryStateRepository = $countryStateRepository;
        $this->salutationRepository = $salutationRepository;
        $this->countryRepository = $countryRepository;
        $this->orderRepository = $orderRepository;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->customerAddressRepository = $customerAddressRepository;
        $this->orderCustomerRepository = $orderCustomerRepository;
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
        Cart                $cart
    ): ShippingMethodCollection {
        $criteria = new Criteria();
        $criteria->addAssociation('availabilityRule');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('prices');
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelContext->getSalesChannel()->getId()));
        $criteria->addFilter(new EqualsFilter('active', 1));

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
     * @throws ResolveCountryException If the country cannot be resolved.
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

        // Filter by sales channel id
        $criteria->addFilter(
            new EqualsFilter('salesChannels.id', $salesChannelContext->getSalesChannel()->getId())
        );

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
     * @return string The ID of the country.
     *
     * @throws ResolveCountryException
     */
    public function getCountryId(string $isoCode, SalesChannelContext $salesChannelContext): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $isoCode));

        $country = $this->countryRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$country) {
            throw new ResolveCountryException(sprintf('Country with ISO code "%s" not found.', $isoCode));
        }

        return $country->getId();
    }

    /**
     * Retrieves the salutation ID for the 'undefined' salutation key.
     *
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @return string The ID of the salutation.
     * @throws ResolveCountryException
     */
    public function getSalutationId(SalesChannelContext $salesChannelContext): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));

        $salutation = $this->salutationRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$salutation) {
            throw new ResolveCountryException(sprintf('Salutation with key undefined not found.'));
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
    public function getStateId(
        string              $administrativeArea,
        string              $countryCode,
        SalesChannelContext $salesChannelContext
    ): ?string {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shortCode', $administrativeArea));
        /** @var CountryStateEntity|null $state */
        $state = $this->countryStateRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$state) {
            $shortCode = $countryCode . '-' . $administrativeArea;
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('shortCode', $shortCode));
            /** @var CountryStateEntity|null $state */
            $state = $this->countryStateRepository->search($criteria, $salesChannelContext->getContext())->first();
        }

        return $state?->getId();
    }

    /**
     * Retrieves a customer by ID with necessary associations.
     *
     * @param string $customerId The ID of the customer to retrieve.
     * @param SalesChannelContext $salesChannelContext The sales channel context for the query.
     *
     * @return CustomerEntity The customer entity.
     * @throws Exception If the customer is not found.
     *
     */
    public function findCustomerById(string $customerId, SalesChannelContext $salesChannelContext): CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultShippingAddress.country');

        $customer = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$customer) {
            throw new Exception(sprintf('Customer with id "%s" not found.', $customerId));
        }

        return $customer;
    }

    /**
     * Creates a guest customer with a default billing and shipping address.
     * @param SalesChannelContext $salesChannelContext The sales channel context containing customer group and payment
     * method details.
     * @param string $guestEmail The email address for the guest customer.
     * @param array $newAddress The address details for the customer
     * @return CustomerEntity The created guest customer entity.
     * @throws ResolveCountryException
     */
    public function createGuestCustomer(
        SalesChannelContext $salesChannelContext,
        string              $guestEmail,
        array               $newAddress
    ): CustomerEntity {
        // Guest data
        $customerId = Uuid::randomHex();
        $firstName = !empty($newAddress['firstName']) ? $newAddress['firstName'] : 'Guest';
        $lastName = !empty($newAddress['lastName']) ? $newAddress['lastName'] : 'Guest';
        $email = $guestEmail !== '' ? $guestEmail : 'adyen.guest@guest.com';
        $salutationId = $this->getSalutationId($salesChannelContext);
        $password = null;
        $groupId = $salesChannelContext->getCurrentCustomerGroup()->getId();
        $paymentMethodId = $salesChannelContext->getPaymentMethod()->getId();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $customerNumber = Uuid::randomHex();
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Address data
        $addressId = Uuid::randomHex();
        $newAddressData = $this->getAddressData($newAddress, $salesChannelContext);

        $customerData = [
            [
                'id' => $customerId,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'guest' => true,
                'email' => $email,
                'salutationId' => $salutationId,
                'password' => $password,
                'groupId' => $groupId,
                'defaultPaymentMethodId' => $paymentMethodId,
                'salesChannelId' => $salesChannelId,
                'customerNumber' => $customerNumber,
                'remoteAddress' => $remoteAddress,
                'defaultBillingAddress' => [
                    'id' => $addressId,
                    'countryId' => $newAddressData['countryId'],
                    'countryStateId' => $newAddressData['stateId'],
                    'city' => $newAddressData['city'],
                    'street' => $newAddressData['street'],
                    'additionalAddressLine1' => $newAddressData['additionalAddressLine1'],
                    'additionalAddressLine2' => $newAddressData['additionalAddressLine2'],
                    'zipcode' => $newAddressData['zipcode'],
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'salutationId' => $salutationId,
                ],
                'defaultShippingAddress' => [
                    'id' => $addressId,
                    'countryId' => $newAddressData['countryId'],
                    'countryStateId' => $newAddressData['stateId'],
                    'city' => $newAddressData['city'],
                    'street' => $newAddressData['street'],
                    'additionalAddressLine1' => $newAddressData['additionalAddressLine1'],
                    'additionalAddressLine2' => $newAddressData['additionalAddressLine2'],
                    'zipcode' => $newAddressData['zipcode'],
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
            throw new Exception('Customer not found');
        }

        return $customer;
    }

    /**
     * @param array $newAddress
     * @param CustomerEntity $customer
     * @param SalesChannelContext $salesChannelContext
     *
     * @return CustomerAddressEntity
     *
     * @throws ResolveCountryException
     */
    public function createAddress(
        array $newAddress,
        CustomerEntity $customer,
        SalesChannelContext $salesChannelContext
    ): CustomerAddressEntity {
        $newAddressData = $this->getAddressData($newAddress, $salesChannelContext);
        $firstName = !empty($newAddress['firstName']) ?  $newAddress['firstName'] : $customer->getFirstName();
        $lastName = !empty($newAddress['lastName']) ? $newAddress['lastName'] : $customer->getLastName();
        $addressId = Uuid::randomHex();

        $addressData = [
            [
                'id' => $addressId,
                'customerId' => $customer->getId(),
                'countryId' => $newAddressData['countryId'],
                'countryStateId' => $newAddressData['stateId'],
                'city' => $newAddressData['city'],
                'street' => $newAddressData['street'],
                'zipcode' => $newAddressData['zipcode'],
                'firstName' => $firstName,
                'lastName' => $lastName,
                'salutationId' => $customer->getSalutationId() ?? '',
                'phoneNumber' => $newAddressData['phoneNumber'],
            ],
        ];

        // Create order address
        $this->customerAddressRepository->create($addressData, $salesChannelContext->getContext());

        // Get customer address by id
        $criteria = new Criteria();
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addFilter(new EqualsFilter('id', $addressId));

        return $this->customerAddressRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->first();
    }

    /**
     * @param CustomerAddressEntity $customerAddress
     * @param CustomerEntity $customer
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    public function updateDefaultCustomerAddress(
        CustomerAddressEntity $customerAddress,
        CustomerEntity $customer,
        SalesChannelContext $salesChannelContext
    ): void {
        $data = [
            [
                'id' => $customer->getId(),
                'defaultBillingAddressId' => $customerAddress->getId(),
                'defaultShippingAddressId' => $customerAddress->getId()
            ]
        ];

        $this->customerRepository->update($data, $salesChannelContext->getContext());
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity|null
     */
    public function getOrderById(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $orderId))
            ->addAssociation('lineItems')
            ->addAssociation('lineItems.downloads')
            ->addAssociation('transactions')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('deliveries.positions.orderLineItem')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('deliveries.shippingOrderAddress.countryState');

        return $this->orderRepository->search($criteria, $context)->first();
    }

    /**
     * @param array $orderData
     * @param Context $context
     * @return void
     */
    public function upsertOrder(array $orderData, Context $context): void
    {
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($orderData): void {
            $this->orderRepository->upsert([$orderData], $context);
        });
    }

    /**
     * @param array $newAddress
     * @param CustomerEntity $customer
     * @param string $orderAddressId
     * @param string $customerOrderId
     * @param SalesChannelContext $salesChannelContext
     * @return CustomerAddressEntity
     * @throws ResolveCountryException
     */
    public function updateOrderAddressAndCustomer(
        array               $newAddress,
        CustomerEntity      $customer,
        string              $orderAddressId,
        string              $customerOrderId,
        SalesChannelContext $salesChannelContext
    ): CustomerAddressEntity {
        $newAddressData = $this->getAddressData($newAddress, $salesChannelContext);
        $firstName = !empty($newAddress['firstName']) ?  $newAddress['firstName'] : $customer->getFirstName();
        $lastName = !empty($newAddress['lastName']) ? $newAddress['lastName'] : $customer->getLastName();

        $addressData = [
            [
                'id' => $orderAddressId,
                'countryId' => $newAddressData['countryId'],
                'countryStateId' => $newAddressData['stateId'],
                'city' => $newAddressData['city'],
                'street' => $newAddressData['street'],
                'zipcode' => $newAddressData['zipcode'],
                'firstName' => $firstName,
                'lastName' => $lastName,
                'salutationId' => $customer->getSalutationId() ?? '',
                'phoneNumber' => $newAddressData['phoneNumber']
            ],
        ];

        // Update order address
        $this->orderAddressRepository->update($addressData, $salesChannelContext->getContext());

        $customerAddressId = $customer->getDefaultBillingAddress()->getId();
        $customerAddressData = [
            [
                'id' => $customerAddressId,
                'countryId' => $newAddressData['countryId'],
                'countryStateId' => $newAddressData['stateId'],
                'city' => $newAddressData['city'],
                'street' => $newAddressData['street'],
                'zipcode' => $newAddressData['zipcode'],
                'firstName' => $firstName,
                'lastName' => $lastName,
                'salutationId' => $customer->getSalutationId() ?? '',
                'phoneNumber' => $newAddressData['phoneNumber']
            ],
        ];

        // Update customer address
        $this->customerAddressRepository->update($customerAddressData, $salesChannelContext->getContext());

        if (isset($newAddress['email']) && $newAddress['email']) {
            $customerData = [
                [
                    'id' => $customer->getId(),
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'salutationId' => $customer->getSalutationId() ?? '',
                    'email' => $newAddress['email']
                ],
            ];

            // Update customer data
            $this->customerRepository->update($customerData, $salesChannelContext->getContext());

            $customerOrderData = [
                [
                    'id' => $customerOrderId,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'salutationId' => $customer->getSalutationId() ?? '',
                    'email' => $newAddress['email']
                ],
            ];

            // Update order customer data
            $this->orderCustomerRepository->update($customerOrderData, $salesChannelContext->getContext());
        }

        // Get customer address by id
        $criteria = new Criteria();
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addFilter(new EqualsFilter('id', $customerAddressId));

        return $this->customerAddressRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->first();
    }

    /**
     * @param array $newAddress
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws ResolveCountryException
     */
    private function getAddressData(array $newAddress, SalesChannelContext $salesChannelContext): array
    {
        $countryCode = !empty($newAddress['countryCode']) ? $newAddress['countryCode'] :
            $salesChannelContext->getShippingLocation()->getCountry()->getIso();
        $countryId = $this->getCountryId($countryCode, $salesChannelContext);
        $stateId = null;
        if (!empty($newAddress['state']) && $countryCode) {
            $stateId = $this->getStateId($newAddress['state'], $countryCode, $salesChannelContext);
        }
        $city = !empty($newAddress['city']) ? $newAddress['city']  : 'Adyen Guest City';
        $street = !empty($newAddress['street']) ? $newAddress['street'] : 'Adyen Guest Street 1';
        $zipcode = $newAddress['zipcode'] ?? $newAddress['postalCode'] ?? '1111';
        $additionalAddressLine1 =  !empty($newAddress['address2']) ? $newAddress['address2'] : '';
        $additionalAddressLine2 =  !empty($newAddress['address3']) ? $newAddress['address3'] : '';
        $phoneNumber = !empty($newAddress['phoneNumber']) ? $newAddress['phoneNumber'] : '';

        return [
            'countryId' =>  $countryId,
            'stateId' => $stateId,
            'city' => $city,
            'street' => $street,
            'zipcode' => $zipcode,
            'additionalAddressLine1' => $additionalAddressLine1,
            'additionalAddressLine2' => $additionalAddressLine2,
            'phoneNumber' => $phoneNumber
        ];
    }
}
