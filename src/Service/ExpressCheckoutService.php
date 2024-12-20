<?php

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Util\Currency;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ExpressCheckoutService
{

    /** @var CartService */
    private $cartService;

    /**
     * @var EntityRepository
     */
    private $countryRepository;

    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * @var Currency
     */
    private Currency $currencyUtil;

    public function __construct(
        CartService $cartService,
        EntityRepository $countryRepository,
        PaymentMethodsService $paymentMethodsService,
        Currency $currencyUtil
    ) {
        $this->cartService = $cartService;
        $this->countryRepository = $countryRepository;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->currencyUtil = $currencyUtil;
    }

    public function getExpressCheckoutConfigOnProductPage(
        string $productId,
        int $quantity,
        SalesChannelContext $salesChannelContext
    ): array {
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $lineItem = new LineItem($productId, 'product', $productId, $quantity);
        $cart = $this->cartService->createNew($tokenNew = Uuid::randomHex());
        $cart->add($lineItem);
        $expressCheckoutSalesChannelContext = new SalesChannelContext(
            $salesChannelContext->getContext(),
            $tokenNew,
            $options[SalesChannelContextService::DOMAIN_ID] ?? null,
            $salesChannelContext->getSalesChannel(),
            $salesChannelContext->getCurrency(),
            $salesChannelContext->getCurrentCustomerGroup(),
            $salesChannelContext->getTaxRules(),
            $salesChannelContext->getPaymentMethod(),
            $salesChannelContext->getShippingMethod(),
            $salesChannelContext->getShippingLocation(),
            $salesChannelContext->getCustomer(),
            $salesChannelContext->getItemRounding(),
            $salesChannelContext->getTotalRounding()
        );
        $cart = $this->cartService->recalculate($cart, $expressCheckoutSalesChannelContext);

        $amountInMinorUnits = $this->currencyUtil->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        $paymentMethods = $this->paymentMethodsService->getPaymentMethods($expressCheckoutSalesChannelContext);

        return [
            'currency' => $currency,
            'amount' => $amountInMinorUnits,
            'countryCode' => $this->getCountryCode(
                $salesChannelContext->getCustomer(),
                $salesChannelContext
            ),
            'paymentMethodsResponse' => json_encode($paymentMethods),
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
}
