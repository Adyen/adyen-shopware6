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

use Adyen\Client;
use Adyen\Environment;
use Adyen\HttpClient\ClientInterface;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureConfigurationResolver;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

class CaptureServiceTest extends AdyenTestCase
{
    private const STATE_SHIPPED = 'state-shipped';
    private const STATE_OPEN = 'state-open';

    private MockObject $httpClient;
    private MockObject $orderTransactionRepository;
    private MockObject $captureRepository;
    private MockObject $configurationService;
    private MockObject $resolver;
    private CaptureService $captureService;
    private OrderTransactionEntity $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(ClientInterface::class);
        $client = new Client();
        $client->setEnvironment(Environment::TEST);
        $client->setHttpClient($this->httpClient);
        $clientService = $this->getSimpleMock(ClientService::class);
        $clientService->method('getClient')->willReturn($client);

        $this->configurationService = $this->getSimpleMock(ConfigurationService::class);
        $this->configurationService->method('getMerchantAccount')->willReturn('TestMerchant');

        $this->orderTransactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $this->captureRepository = $this->getSimpleMock(EntityRepository::class);
        $adyenPaymentCaptureRepository = $this->getSimpleMock(AdyenPaymentCaptureRepository::class);
        $adyenPaymentCaptureRepository->method('getRepository')->willReturn($this->captureRepository);
        $this->resolver = $this->getSimpleMock(CaptureConfigurationResolver::class);

        $this->captureService = new CaptureService(
            $this->createOrderRepository(),
            $this->orderTransactionRepository,
            $adyenPaymentCaptureRepository,
            $this->configurationService,
            $clientService,
            $this->getSimpleMock(AdyenPaymentService::class),
            $this->resolver
        );
        $this->captureService->setLogger($this->createMock(LoggerInterface::class));

        $this->transaction = new OrderTransactionEntity();
        $this->transaction->setId('transaction-1');
        $this->transaction->setPaymentMethodId('pm-cards');
        $this->transaction->setCustomFields([PaymentResponseHandler::ORIGINAL_PSP_REFERENCE => 'PSP123']);
    }

    public function testNoAuthorisedTransactionMeansNothingToCapture(): void
    {
        $this->orderTransactionRepository->method('getFirstAdyenOrderTransactionByStates')->willReturn(null);
        $this->httpClient->expects(self::never())->method('requestHttp');

        try {
            $this->captureService->doCapture('10001', 1999, Context::createDefaultContext());
            self::fail('CaptureException was not thrown.');
        } catch (CaptureException $exception) {
            self::assertSame(CaptureService::REASON_NOTHING_TO_CAPTURE, $exception->reason);
        }
    }

    public function testCaptureOnShipmentWaitsForTheConfiguredDeliveryState(): void
    {
        $this->givenAuthorisedTransaction(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_SHIPPED, 60)
        );
        $this->httpClient->expects(self::never())->method('requestHttp');

        try {
            $this->captureService->doCapture('10001', 1999, Context::createDefaultContext());
            self::fail('CaptureException was not thrown.');
        } catch (CaptureException $exception) {
            self::assertSame(CaptureService::REASON_DELIVERY_STATE_MISMATCH, $exception->reason);
        }
    }

    public function testCaptureOnShipmentCapturesOnceTheDeliveryStateIsReached(): void
    {
        $this->givenAuthorisedTransaction(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_OPEN, 60)
        );
        $this->expectCaptureRequest(1999);

        $results = $this->captureService->doCapture('10001', 1999, Context::createDefaultContext());

        self::assertCount(1, $results);
    }

    /**
     * Manual capture from the administration is allowed before the delivery state is reached (BA), and webhooks
     * pass the notification amount as a string.
     */
    public function testAdminCaptureSkipsTheDeliveryCheckAndAcceptsAStringAmount(): void
    {
        $this->givenAuthorisedTransaction(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_SHIPPED, 60)
        );
        $this->expectCaptureRequest(1999);

        $this->captureService->doCapture('10001', '1999', Context::createDefaultContext(), true);
    }

    public function testManualModeCapturesWithoutDeliveryCheck(): void
    {
        $this->givenAuthorisedTransaction(new CaptureConfiguration(CaptureMode::MANUAL));
        $this->expectCaptureRequest(500);

        $this->captureService->doCapture('10001', 500, Context::createDefaultContext());
    }

    public function testAutomaticCaptureModeIsRejected(): void
    {
        $this->givenAuthorisedTransaction(new CaptureConfiguration(CaptureMode::IMMEDIATE));
        $this->httpClient->expects(self::never())->method('requestHttp');

        $this->expectException(CaptureException::class);
        $this->expectExceptionMessage('Manual capture disabled.');

        $this->captureService->doCapture('10001', 1999, Context::createDefaultContext());
    }

    public function testRescheduleTimeUsesTheRetryIntervalOfThePayment(): void
    {
        $this->configurationService->method('getRescheduleTime')->willReturn(43200);

        $before = time();
        $withConfiguration = $this->captureService->getRescheduleNotificationTime(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_SHIPPED, 600)
        );
        $withoutConfiguration = $this->captureService->getRescheduleNotificationTime();

        self::assertEqualsWithDelta($before + 600, $withConfiguration->getTimestamp(), 5);
        self::assertEqualsWithDelta($before + 43200, $withoutConfiguration->getTimestamp(), 5);
    }

    private function givenAuthorisedTransaction(CaptureConfiguration $configuration): void
    {
        $this->orderTransactionRepository->method('getFirstAdyenOrderTransactionByStates')
            ->willReturn($this->transaction);
        $this->resolver->method('resolveForTransaction')
            ->with($this->transaction, 'sales-channel-1')
            ->willReturn($configuration);
    }

    private function expectCaptureRequest(int $amount): void
    {
        $this->httpClient->expects(self::once())
            ->method('requestHttp')
            ->with(
                $this->anything(),
                $this->stringEndsWith('/payments/PSP123/captures'),
                $this->callback(
                    static fn ($body): bool
                        => json_decode(json_encode($body), true)['amount']['value'] === $amount
                ),
                'post'
            )
            ->willReturn([
                'merchantAccount' => 'TestMerchant',
                'paymentPspReference' => 'PSP123',
                'pspReference' => 'CAPTURE1',
                'reference' => '10001',
                'status' => 'received',
                'amount' => ['currency' => 'EUR', 'value' => $amount],
            ]);

        $this->captureRepository->expects(self::once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => $data[0]['amount'] === $amount
                && $data[0]['pspReference'] === 'CAPTURE1'
                && $data[0]['orderTransactionId'] === 'transaction-1'));
    }

    private function createOrderRepository(): MockObject
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-eur');
        $currency->setIsoCode('EUR');

        $state = new StateMachineStateEntity();
        $state->setId(self::STATE_OPEN);
        $delivery = new OrderDeliveryEntity();
        $delivery->setId('delivery-1');
        $delivery->setStateMachineState($state);

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);
        $order->setLineItems(new OrderLineItemCollection());
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));

        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->method('getOrderByOrderNumber')->willReturn($order);

        return $orderRepository;
    }
}
