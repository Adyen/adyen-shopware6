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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Handlers\CardsPaymentMethodHandler;
use Adyen\Shopware\Handlers\PaypalPaymentMethodHandler;
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
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class PaymentRequestServiceTest extends AdyenTestCase
{
    private MockObject $captureConfigurationResolver;
    private MockObject $orderTransactionRepository;
    private PaymentRequestService $paymentRequestService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureConfigurationResolver = $this->getSimpleMock(CaptureConfigurationResolver::class);
        $this->orderTransactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);

        $this->paymentRequestService = new PaymentRequestService(
            $this->getSimpleMock(ClientService::class),
            $this->getSimpleMock(ConfigurationService::class),
            $this->captureConfigurationResolver,
            $this->getSimpleMock(Currency::class),
            $this->getSimpleMock(CheckoutStateDataValidator::class),
            $this->getSimpleMock(RatePayDeviceFingerprintParamsProvider::class),
            $this->getSimpleMock(SalesChannelRepository::class),
            $this->getSimpleMock(EntityRepository::class),
            new RequestStack(),
            $this->orderTransactionRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testStoreCaptureConfigurationWritesTheSnapshotAndKeepsOtherCustomFields(): void
    {
        $configuration = new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 600);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $transaction->setPaymentMethodId('pm-cards');
        $transaction->setCustomFields(['originalPspReference' => 'PSP123']);

        $this->captureConfigurationResolver->expects(self::once())
            ->method('resolve')
            ->with(CardsPaymentMethodHandler::class, 'pm-cards', 'sales-channel-1')
            ->willReturn($configuration);
        $this->orderTransactionRepository->expects(self::once())
            ->method('updateCustomFields')
            ->with($transaction);

        $result = $this->paymentRequestService->storeCaptureConfiguration(
            $transaction,
            CardsPaymentMethodHandler::class,
            'sales-channel-1'
        );

        self::assertSame($configuration, $result);
        self::assertSame(
            [
                'originalPspReference' => 'PSP123',
                CaptureConfigurationResolver::CUSTOM_FIELD_KEY => $configuration->toArray(),
            ],
            $transaction->getCustomFields()
        );
    }

    public function testStoreCaptureConfigurationStoresTheGivenConfigurationWithoutResolvingIt(): void
    {
        // E.g. the configuration sent with a PayPal /payments request before the order existed.
        $configuration = new CaptureConfiguration(CaptureMode::MANUAL);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $transaction->setPaymentMethodId('pm-paypal');

        $this->captureConfigurationResolver->expects(self::never())->method('resolve');
        $this->orderTransactionRepository->expects(self::once())->method('updateCustomFields')->with($transaction);

        $result = $this->paymentRequestService->storeCaptureConfiguration(
            $transaction,
            PaypalPaymentMethodHandler::class,
            'sales-channel-1',
            $configuration
        );

        self::assertSame($configuration, $result);
        self::assertSame(
            [CaptureConfigurationResolver::CUSTOM_FIELD_KEY => $configuration->toArray()],
            $transaction->getCustomFields()
        );
    }

    public function testResolvePaypalCaptureConfigurationUsesThePaypalHandler(): void
    {
        $configuration = new CaptureConfiguration(CaptureMode::DELAYED, 2);
        $this->captureConfigurationResolver->expects(self::once())
            ->method('resolveByHandler')
            ->with(PaypalPaymentMethodHandler::class, 'sales-channel-1')
            ->willReturn($configuration);

        self::assertSame(
            $configuration,
            $this->paymentRequestService->resolvePaypalCaptureConfiguration('sales-channel-1')
        );
    }

    public function testStoreCaptureConfigurationWithoutExistingCustomFields(): void
    {
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $transaction->setPaymentMethodId('pm-cards');

        $this->captureConfigurationResolver->method('resolve')->willReturn(CaptureConfiguration::automatic());

        $this->paymentRequestService->storeCaptureConfiguration($transaction, CardsPaymentMethodHandler::class, null);

        self::assertSame(
            [CaptureConfigurationResolver::CUSTOM_FIELD_KEY => CaptureConfiguration::automatic()->toArray()],
            $transaction->getCustomFields()
        );
    }

    /**
     * Spec §6.
     */
    /**
     * @dataProvider captureRequestFields
     */
    public function testCaptureFieldsOfTheRequest(
        CaptureConfiguration $configuration,
        bool $supportsCaptureConfiguration,
        bool $isOpenInvoice,
        ?bool $expectedManualCapture,
        ?int $expectedCaptureDelayHours
    ): void {
        $request = new IntegrationPaymentRequest();

        $method = new ReflectionMethod(PaymentRequestService::class, 'setAdditionalRequestData');
        $method->setAccessible(true);
        $method->invoke(
            $this->paymentRequestService,
            $request,
            $configuration,
            $supportsCaptureConfiguration,
            $isOpenInvoice
        );

        $additionalData = $request->getAdditionalData();
        self::assertTrue($additionalData['allow3DS2']);
        self::assertSame($expectedManualCapture, $additionalData['manualCapture'] ?? null);
        self::assertSame($expectedCaptureDelayHours, $request->getCaptureDelayHours());
    }

    public static function captureRequestFields(): array
    {
        return [
            // The capture delay of the Adyen Customer Area applies, so no delay is sent.
            'immediate' => [new CaptureConfiguration(CaptureMode::IMMEDIATE), true, false, null, null],
            'immediate open invoice' => [new CaptureConfiguration(CaptureMode::IMMEDIATE), true, true, null, 0],
            'delayed 3 days' => [new CaptureConfiguration(CaptureMode::DELAYED, 3), true, false, null, 72],
            'delayed 3 days open invoice' => [new CaptureConfiguration(CaptureMode::DELAYED, 3), true, true, null, 72],
            'delayed without days' => [new CaptureConfiguration(CaptureMode::DELAYED), true, false, null, null],
            'manual' => [new CaptureConfiguration(CaptureMode::MANUAL), true, false, true, null],
            'manual open invoice' => [new CaptureConfiguration(CaptureMode::MANUAL), true, true, true, null],
            'on shipment' => [
                new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'id', 60),
                true,
                false,
                true,
                null,
            ],
            'method without manual capture support' => [CaptureConfiguration::automatic(), false, false, null, null],
            'open invoice without manual capture support' => [CaptureConfiguration::automatic(), false, true, null, 0],
        ];
    }
}
