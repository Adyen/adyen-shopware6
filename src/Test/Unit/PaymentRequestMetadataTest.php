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

use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Adyen\Shopware\Service\CaptureConfigurationResolver;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Shopware\Util\CheckoutStateDataValidator;
use Adyen\Shopware\Util\Currency;
use Adyen\Shopware\Util\RatePayDeviceFingerprintParamsProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The order transaction ID is sent in the payment metadata, so the AUTHORISATION webhook can be linked to it.
 */
class PaymentRequestMetadataTest extends AdyenTestCase
{
    private const TRANSACTION_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';

    public function testTransactionIdIsSentInMetadata(): void
    {
        $request = $this->buildRequest([], self::TRANSACTION_ID);

        $this->assertSame(
            self::TRANSACTION_ID,
            $request->getMetadata()[PaymentRequestService::METADATA_ORDER_TRANSACTION_ID]
        );
    }

    public function testMetadataFromStateDataIsNotSent(): void
    {
        $request = $this->buildRequest(
            ['metadata' => [
                PaymentRequestService::METADATA_ORDER_TRANSACTION_ID => 'from-the-browser',
                'other' => 'from-the-browser',
            ]],
            self::TRANSACTION_ID
        );

        $this->assertSame(
            [PaymentRequestService::METADATA_ORDER_TRANSACTION_ID => self::TRANSACTION_ID],
            $request->getMetadata()
        );
    }

    public function testNoMetadataWithoutTransactionId(): void
    {
        $request = $this->buildRequest(['metadata' => ['other' => 'from-the-browser']], null);

        $this->assertEmpty($request->getMetadata());
    }

    public function testMetadataFromStateDataIsNotSentForTheCartRequest(): void
    {
        // PayPal sends the payment before the order exists, from the cart.
        $cart = new Cart('cart-token');
        $cart->setPrice(new CartPrice(
            10.0,
            10.0,
            10.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        $request = $this->createService()->buildPaymentRequestFromCart(
            $this->createContext(),
            $cart,
            'https://shop.test/return',
            '10001',
            'paypal',
            ['metadata' => [PaymentRequestService::METADATA_ORDER_TRANSACTION_ID => 'from-the-browser']]
        );

        $this->assertEmpty($request->getMetadata());
    }

    private function buildRequest(array $stateData, ?string $orderTransactionId): IntegrationPaymentRequest
    {
        $order = new OrderEntity();
        $order->setOrderNumber('10001');

        return $this->createService()->buildPaymentRequestFromOrder(
            salesChannelContext: $this->createContext(),
            orderEntity: $order,
            returnUrl: 'https://shop.test/return',
            paymentMethodCode: 'klarna',
            stateData: $stateData,
            partialAmount: 1000,
            orderTransactionId: $orderTransactionId
        );
    }

    private function createContext(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-1');

        $context = $this->getSimpleMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return $context;
    }

    private function createService(): PaymentRequestService
    {
        $stateDataValidator = $this->getSimpleMock(CheckoutStateDataValidator::class);
        $stateDataValidator->method('getValidatedAdditionalData')->willReturnArgument(0);

        $salesChannelRepository = $this->getSimpleMock(SalesChannelRepository::class);
        $salesChannelRepository->method('getCurrentDomainUrl')->willReturn('https://shop.test');

        $configurationService = $this->getSimpleMock(ConfigurationService::class);
        $configurationService->method('getMerchantAccount')->willReturn('MerchantAccount');

        $captureConfigurationResolver = $this->getSimpleMock(CaptureConfigurationResolver::class);
        $captureConfigurationResolver->method('resolveByHandler')
            ->willReturn(new CaptureConfiguration(CaptureMode::IMMEDIATE));

        // Addresses, shopper data and browser info are not part of this test.
        $service = $this->getMockBuilder(PaymentRequestService::class)
            ->setConstructorArgs([
                $this->getSimpleMock(ClientService::class),
                $configurationService,
                $captureConfigurationResolver,
                $this->getSimpleMock(Currency::class),
                $stateDataValidator,
                $this->getSimpleMock(RatePayDeviceFingerprintParamsProvider::class),
                $salesChannelRepository,
                $this->getSimpleMock(EntityRepository::class),
                $this->getSimpleMock(RequestStack::class),
                $this->getSimpleMock(OrderTransactionRepository::class),
                $this->createMock(LoggerInterface::class),
            ])
            ->onlyMethods(['setBrowserInfo', 'setDeliveryAddress', 'setBillingAddress', 'setShopperData'])
            ->getMock();

        return $service;
    }
}
