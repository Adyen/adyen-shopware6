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

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ExpressCheckoutService;
use Adyen\Shopware\Service\PaymentMethodsFilterService;
use Adyen\Shopware\Service\Repository\ExpressCheckoutRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Shopware\Util\Currency;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ExpressCheckoutServiceTest extends AdyenTestCase
{
    private MockObject $contextPersister;
    private MockObject $expressCheckoutRepository;
    private ExpressCheckoutService $expressCheckoutService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextPersister = $this->getSimpleMock(SalesChannelContextPersister::class);
        $this->expressCheckoutRepository = $this->getSimpleMock(ExpressCheckoutRepository::class);

        $this->expressCheckoutService = new ExpressCheckoutService(
            $this->getSimpleMock(CartService::class),
            $this->expressCheckoutRepository,
            $this->getSimpleMock(PaymentMethodsFilterService::class),
            $this->getSimpleMock(ClientService::class),
            $this->getSimpleMock(Currency::class),
            $this->contextPersister,
            $this->getSimpleMock(EntityRepository::class),
            $this->getSimpleMock(OrderConverter::class),
            $this->createMock(SalesChannelContextServiceInterface::class)
        );
    }

    public function testShippingMethodIsPersistedWithTheContextToken(): void
    {
        // A context rebuilt from the token (PayPal express finalize) must carry the shipping method the shopper was
        // charged for instead of the sales channel default, which may be blocked for the delivery country.
        $context = $this->createSalesChannelContext();

        $this->contextPersister->expects($this->once())
            ->method('save')
            ->with(
                'cart-token',
                [
                    SalesChannelContextService::PAYMENT_METHOD_ID => 'paypal',
                    SalesChannelContextService::SHIPPING_METHOD_ID => 'express',
                ],
                'sales-channel-1'
            );

        $this->expressCheckoutService->changeContext(null, $context);
    }

    public function testCustomerIsPersistedWithTheShippingMethod(): void
    {
        $context = $this->createSalesChannelContext();
        $address = new CustomerAddressEntity();
        $address->setId('address-1');
        $address->setCountry(new CountryEntity());
        $customer = new CustomerEntity();
        $customer->setId('customer-1');
        $customer->setDefaultBillingAddress($address);
        $this->expressCheckoutRepository->method('findCustomerById')
            ->with('customer-1', $context)
            ->willReturn($customer);

        // The customer id goes into the payload; the customer_id column is left to Shopware's own session handling
        $this->contextPersister->expects($this->once())
            ->method('save')
            ->with(
                'cart-token',
                [
                    SalesChannelContextService::PAYMENT_METHOD_ID => 'paypal',
                    SalesChannelContextService::SHIPPING_METHOD_ID => 'express',
                    SalesChannelContextService::CUSTOMER_ID => 'customer-1',
                ],
                'sales-channel-1'
            );

        $this->expressCheckoutService->changeContext('customer-1', $context);
    }

    private function createSalesChannelContext(): MockObject
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('paypal');
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('express');
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-1');

        $context = $this->getSimpleMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getToken')->willReturn('cart-token');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);
        $context->method('getShippingMethod')->willReturn($shippingMethod);
        $context->method('getCustomer')->willReturn(null);

        return $context;
    }
}
