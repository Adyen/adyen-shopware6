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
    private EntityRepository $countryRepository;

    public function __construct(
        EntityRepository $shippingMethodRepository,
        EntityRepository $countryRepository
    ) {
        $this->shippingMethodRepository = $shippingMethodRepository;
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
}
