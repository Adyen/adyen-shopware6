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

use Adyen\Shopware\Exception\CaptureConfigurationValidationException;
use Adyen\Shopware\Handlers\CardsPaymentMethodHandler;
use Adyen\Shopware\Handlers\IdealPaymentMethodHandler;
use Adyen\Shopware\Service\CaptureConfigurationResolver;
use Adyen\Shopware\Service\CaptureConfigurationService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CaptureConfigurationServiceTest extends AdyenTestCase
{
    private const PAYMENT_METHOD_ID = '0190f0e1a7c47a0d9e2b3c4d5e6f7a01';
    private const SALES_CHANNEL_ID = '0190f0e1a7c47a0d9e2b3c4d5e6f7a02';
    private const STATE_SHIPPED = '0190f0e1a7c47a0d9e2b3c4d5e6f7a03';
    private const PM = 'AdyenPaymentShopware6.captureConfiguration.' . self::PAYMENT_METHOD_ID . '.';

    /**
     * @var array<string, array<string, mixed>> Stored rows per scope ('' = All Sales Channels).
     */
    private array $store = [];

    /**
     * @var list<array{values: array<string, mixed>, salesChannelId: string|null}>
     */
    private array $saved = [];

    private string $handler = CardsPaymentMethodHandler::class;
    private MockObject $connection;
    private CaptureConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $systemConfigService = $this->getSimpleMock(SystemConfigService::class);
        $systemConfigService->method('getDomain')->willReturnCallback(
            function (string $domain, ?string $salesChannelId = null): array {
                $prefix = rtrim($domain, '.') . '.';

                return array_filter(
                    $this->store[$salesChannelId ?? ''] ?? [],
                    static fn (string $key): bool => str_starts_with($key, $prefix),
                    ARRAY_FILTER_USE_KEY
                );
            }
        );
        $systemConfigService->method('get')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null) {
                if ($salesChannelId !== null && array_key_exists($key, $this->store[$salesChannelId] ?? [])) {
                    return $this->store[$salesChannelId][$key];
                }

                return $this->store[''][$key] ?? null;
            }
        );
        // One save writes every field in one transaction.
        $systemConfigService->method('set')->willReturnCallback(
            function (string $key, $value, ?string $salesChannelId = null): void {
                $save = count($this->saved) - 1;
                $this->saved[$save]['values'][$key] = $value;
                $this->saved[$save]['salesChannelId'] = $salesChannelId;
            }
        );

        $paymentMethodRepository = $this->getSimpleMock(EntityRepository::class);
        $paymentMethodRepository->method('search')->willReturnCallback(function (): EntitySearchResult {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId(self::PAYMENT_METHOD_ID);
            $paymentMethod->setHandlerIdentifier($this->handler);

            $result = $this->getSimpleMock(EntitySearchResult::class);
            $result->method('first')->willReturn($paymentMethod);

            return $result;
        });

        $this->connection = $this->getSimpleMock(Connection::class);
        $this->connection->method('fetchOne')->willReturn('1');
        $this->connection->method('transactional')->willReturnCallback(function (\Closure $func) {
            $this->saved[] = ['values' => [], 'salesChannelId' => null];

            return $func();
        });

        $configurationService = new ConfigurationService($systemConfigService, null);
        $this->service = new CaptureConfigurationService(
            $systemConfigService,
            $configurationService,
            new CaptureConfigurationResolver($configurationService, $paymentMethodRepository),
            $paymentMethodRepository,
            $this->connection
        );
    }

    public function testNonAdyenAndUnsupportedMethodsAreNotSupported(): void
    {
        foreach ([InvoicePayment::class, IdealPaymentMethodHandler::class] as $handler) {
            $this->handler = $handler;

            self::assertSame(['supported' => false], $this->get(null));
        }

        $this->expectException(CaptureConfigurationValidationException::class);
        $this->service->save(self::PAYMENT_METHOD_ID, null, ['mode' => 'manual'], Context::createDefaultContext());
    }

    public function testAllSalesChannelsShowsTheGlobalModeWhenNothingIsStored(): void
    {
        $this->store['']['AdyenPaymentShopware6.config.captureMode'] = 'delayed';
        $this->store['']['AdyenPaymentShopware6.config.captureDelayDays'] = 2;

        $result = $this->get(null);

        self::assertTrue($result['supported']);
        self::assertNull($result['values']['mode']);
        self::assertSame('global', $result['inherited']['mode']);
        self::assertSame(2, $result['inherited']['delayDays']);
        self::assertSame('delayed', $result['globalMode']);
    }

    public function testSalesChannelInheritsThePaymentMethodValueOfAllSalesChannels(): void
    {
        $this->store['']['AdyenPaymentShopware6.config.captureMode'] = 'immediate';
        $this->store[''][self::PM . 'mode'] = 'manual';

        $result = $this->get(self::SALES_CHANNEL_ID);

        self::assertNull($result['values']['mode']);
        self::assertSame('manual', $result['inherited']['mode']);
        self::assertSame('immediate', $result['globalMode']);
    }

    public function testSaveWritesEveryFieldOfTheScope(): void
    {
        $this->save(self::SALES_CHANNEL_ID, ['mode' => 'delayed', 'delayDays' => '3']);

        self::assertSame(
            [
                'values' => [
                    self::PM . 'mode' => 'delayed',
                    self::PM . 'delayDays' => 3,
                    self::PM . 'orderState' => null,
                    self::PM . 'retryInterval' => null,
                ],
                'salesChannelId' => self::SALES_CHANNEL_ID,
            ],
            $this->saved[0]
        );
    }

    public function testCaptureOnShipmentAcceptsAnInheritedDeliveryState(): void
    {
        $this->store['']['AdyenPaymentShopware6.config.orderState'] = self::STATE_SHIPPED;

        $this->save(null, ['mode' => 'on_shipment']);

        self::assertCount(1, $this->saved);
    }

    /**
     * @dataProvider invalidValues
     */
    public function testInvalidValuesAreRejected(array $values): void
    {
        $this->expectException(CaptureConfigurationValidationException::class);

        $this->save(null, $values);
    }

    public static function invalidValues(): array
    {
        return [
            'unknown mode' => [['mode' => 'auto']],
            'delay 0' => [['mode' => 'delayed', 'delayDays' => 0]],
            'delay 8' => [['mode' => 'delayed', 'delayDays' => 8]],
            'delay not a number' => [['mode' => 'delayed', 'delayDays' => 'abc']],
            'retry interval 0' => [['mode' => 'on_shipment', 'retryInterval' => 0]],
            'on shipment without any delivery state' => [['mode' => 'on_shipment']],
        ];
    }

    public function testUnknownDeliveryStateIsRejected(): void
    {
        $this->connection = $this->getSimpleMock(Connection::class);
        $this->connection->method('fetchOne')->willReturn(false);
        $this->setUpServiceWithConnection();

        $this->expectException(CaptureConfigurationValidationException::class);

        $this->save(null, ['mode' => 'on_shipment', 'orderState' => self::STATE_SHIPPED]);
    }

    private function setUpServiceWithConnection(): void
    {
        $reflection = new \ReflectionProperty(CaptureConfigurationService::class, 'connection');
        $reflection->setAccessible(true);
        $reflection->setValue($this->service, $this->connection);
    }

    private function get(?string $salesChannelId): array
    {
        return $this->service->getForAdmin(self::PAYMENT_METHOD_ID, $salesChannelId, Context::createDefaultContext());
    }

    private function save(?string $salesChannelId, array $values): void
    {
        $this->service->save(self::PAYMENT_METHOD_ID, $salesChannelId, $values, Context::createDefaultContext());
    }
}
