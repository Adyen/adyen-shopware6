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
use Adyen\Shopware\Handlers\KlarnaPayLaterPaymentMethodHandler;
use Adyen\Shopware\Service\CaptureConfigurationMigrator;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CaptureConfigurationMigratorTest extends AdyenTestCase
{
    private const SALES_CHANNEL_B = '0190f0e1a7c47a0d9e2b3c4d5e6f7a8b';
    private const OPEN_INVOICE_METHODS = ['pm-klarna', 'pm-ratepay'];
    private const CONFIG = 'AdyenPaymentShopware6.config.';
    private const PM = 'AdyenPaymentShopware6.captureConfiguration.';
    private const SHIPPED_STATE = 'state-shipped-default';

    /**
     * @var array<string, array<string, mixed>> Stored system_config rows per scope ('' = All Sales Channels).
     */
    private array $store = [];

    private int $writes = 0;
    private CaptureConfigurationMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        $systemConfigService = $this->getSimpleMock(SystemConfigService::class);
        // getDomain() without inheritance: only the rows of exactly this scope.
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
        $systemConfigService->method('set')->willReturnCallback(
            function (string $key, $value, ?string $salesChannelId = null): void {
                $this->write($key, $value, $salesChannelId);
            }
        );
        // get() hides every key of an inactive plugin; the migrator must never depend on it.
        $systemConfigService->method('get')->willReturn(null);

        $connection = $this->getSimpleMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            function (string $sql, array $params): array {
                if (str_contains($sql, 'payment_method')) {
                    return self::OPEN_INVOICE_METHODS;
                }

                $salesChannelIds = [];
                foreach ($this->store as $scope => $rows) {
                    if ($scope !== '' && array_intersect(array_keys($rows), $params['keys'])) {
                        $salesChannelIds[] = $scope;
                    }
                }

                return $salesChannelIds;
            }
        );

        // Id of the "shipped" delivery state of this shop.
        $connection->method('fetchOne')->willReturn(self::SHIPPED_STATE);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $func) => $func());

        $this->migrator = new CaptureConfigurationMigrator($connection, $systemConfigService);
    }

    public function testFreshInstallGetsTheOpenInvoiceDefaults(): void
    {
        // Shopware writes the config.xml defaults before install() and update().
        $this->givenConfig(['captureMode' => 'immediate', 'captureDelayDays' => 1, 'rescheduleTime' => 43200]);

        $this->migrator->migrate();

        self::assertSame('immediate', $this->config('captureMode'));
        foreach (self::OPEN_INVOICE_METHODS as $paymentMethodId) {
            self::assertSame('manual', $this->paymentMethod($paymentMethodId, 'mode'));
            self::assertNull($this->paymentMethod($paymentMethodId, 'orderState'));
        }
        self::assertSame(self::SHIPPED_STATE, $this->config('orderState'));
        self::assertTrue($this->migrator->isMigrated());
    }

    public function testExistingDeliveryStateIsNotReplacedByTheShippedDefault(): void
    {
        $this->givenConfig(['orderState' => 'state-delivered']);

        $this->migrator->migrate();

        self::assertSame('state-delivered', $this->config('orderState'));
    }

    public function testCaptureOnShipmentWithoutAStoredStateGetsTheShippedDefault(): void
    {
        $this->givenConfig(['captureOnShipmentEnabled' => true]);

        $this->migrator->migrate();

        self::assertSame(self::SHIPPED_STATE, $this->config('orderState'));
        self::assertSame(self::SHIPPED_STATE, $this->paymentMethod('pm-klarna', 'orderState'));
    }

    public function testShopThatNeverSavedTheCaptureSettingsKeepsItsBehavior(): void
    {
        // Only the default rescheduleTime is stored, as on the 6-7-15-1 test shop.
        $this->givenConfig(['rescheduleTime' => 43200]);

        $this->migrator->migrate();

        self::assertSame('immediate', $this->config('captureMode'));
        self::assertSame('manual', $this->paymentMethod('pm-klarna', 'mode'));
    }

    public function testManualCaptureBecomesTheGlobalManualMode(): void
    {
        $this->givenConfig(['captureMode' => 'immediate', 'manualCaptureEnabled' => true]);

        $this->migrator->migrate();

        self::assertSame('manual', $this->config('captureMode'));
        self::assertSame('manual', $this->paymentMethod('pm-klarna', 'mode'));
    }

    public function testAutoCaptureForOpenInvoicesBecomesImmediate(): void
    {
        $this->givenConfig(['autoCaptureOpenInvoice' => true, 'captureOnShipmentEnabled' => true]);

        $this->migrator->migrate();

        self::assertSame('immediate', $this->paymentMethod('pm-klarna', 'mode'));
        self::assertSame('immediate', $this->paymentMethod('pm-ratepay', 'mode'));
    }

    public function testCaptureOnShipmentKeepsTheGlobalStateAndInterval(): void
    {
        $this->givenConfig([
            'autoCaptureOpenInvoice' => false,
            'captureOnShipmentEnabled' => true,
            'orderState' => 'state-shipped',
            'rescheduleTime' => 600,
        ]);

        $this->migrator->migrate();

        self::assertSame('immediate', $this->config('captureMode'));
        self::assertSame('on_shipment', $this->paymentMethod('pm-klarna', 'mode'));
        self::assertSame('state-shipped', $this->paymentMethod('pm-klarna', 'orderState'));
        self::assertSame(600, $this->paymentMethod('pm-klarna', 'retryInterval'));
        // Kept: on_shipment methods use them, and the global page still shows them for on_shipment.
        self::assertSame('state-shipped', $this->config('orderState'));
        self::assertSame(600, $this->config('rescheduleTime'));
    }

    public function testSalesChannelWithOnlyItsOwnManualCaptureValue(): void
    {
        $this->givenConfig(['manualCaptureEnabled' => false]);
        $this->givenConfig(['manualCaptureEnabled' => true], self::SALES_CHANNEL_B);

        $this->migrator->migrate();

        self::assertSame('immediate', $this->config('captureMode'));
        self::assertSame('manual', $this->config('captureMode', self::SALES_CHANNEL_B));
        // The open invoice values of sales channel B are inherited from All Sales Channels.
        self::assertNull($this->paymentMethod('pm-klarna', 'mode', self::SALES_CHANNEL_B));
        self::assertSame('manual', $this->paymentMethod('pm-klarna', 'mode'));
    }

    /**
     * The old code read the delivery state and the retry interval only globally, so a value stored for the sales
     * channel was never used and must not be copied.
     */
    public function testSalesChannelCaptureOnShipmentUsesTheGlobalStateAndInterval(): void
    {
        $this->givenConfig(['orderState' => 'state-delivered', 'rescheduleTime' => 43200]);
        $this->givenConfig(
            ['captureOnShipmentEnabled' => true, 'orderState' => 'state-shipped', 'rescheduleTime' => 60],
            self::SALES_CHANNEL_B
        );

        $this->migrator->migrate();

        self::assertSame('manual', $this->paymentMethod('pm-klarna', 'mode'));
        self::assertSame('on_shipment', $this->paymentMethod('pm-klarna', 'mode', self::SALES_CHANNEL_B));
        self::assertSame('state-delivered', $this->paymentMethod('pm-klarna', 'orderState', self::SALES_CHANNEL_B));
        self::assertSame(43200, $this->paymentMethod('pm-klarna', 'retryInterval', self::SALES_CHANNEL_B));
        // Sales channel B did not set manualCaptureEnabled, so it inherits the global capture mode.
        self::assertNull($this->config('captureMode', self::SALES_CHANNEL_B));
    }

    public function testSalesChannelInheritsTheGlobalAutoCaptureValue(): void
    {
        $this->givenConfig(['autoCaptureOpenInvoice' => true]);
        $this->givenConfig(['captureOnShipmentEnabled' => true], self::SALES_CHANNEL_B);

        $this->migrator->migrate();

        // Effective old values in B: auto capture (inherited) wins over capture on shipment, as before.
        self::assertSame('immediate', $this->paymentMethod('pm-klarna', 'mode', self::SALES_CHANNEL_B));
    }

    public function testOldKeysAreDeletedInAllScopes(): void
    {
        $this->givenConfig([
            'manualCaptureEnabled' => true,
            'autoCaptureOpenInvoice' => false,
            'captureOnShipmentEnabled' => false,
        ]);
        $this->givenConfig(['captureOnShipmentEnabled' => true], self::SALES_CHANNEL_B);

        $this->migrator->migrate();

        foreach (['manualCaptureEnabled', 'autoCaptureOpenInvoice', 'captureOnShipmentEnabled'] as $key) {
            self::assertArrayNotHasKey(self::CONFIG . $key, $this->store['']);
            self::assertArrayNotHasKey(self::CONFIG . $key, $this->store[self::SALES_CHANNEL_B]);
        }
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->givenConfig(['manualCaptureEnabled' => true]);
        $this->migrator->migrate();

        // The merchant changes the configuration afterwards.
        $this->write(self::CONFIG . 'captureMode', 'delayed', null);
        $this->write(self::PM . 'pm-klarna.mode', 'immediate', null);
        $writes = $this->writes;

        $this->migrator->migrate();

        self::assertSame($writes, $this->writes);
        self::assertSame('delayed', $this->config('captureMode'));
        self::assertSame('immediate', $this->paymentMethod('pm-klarna', 'mode'));
    }

    public function testNewOpenInvoiceMethodGetsManualOnlyAfterTheMigration(): void
    {
        $this->migrator->applyNewOpenInvoiceDefault('pm-afterpay-touch');
        self::assertNull($this->paymentMethod('pm-afterpay-touch', 'mode'));

        $this->migrator->migrate();
        $this->migrator->applyNewOpenInvoiceDefault('pm-afterpay-touch');

        self::assertSame('manual', $this->paymentMethod('pm-afterpay-touch', 'mode'));
    }

    public function testOpenInvoiceHandlerDetection(): void
    {
        self::assertTrue(CaptureConfigurationMigrator::isOpenInvoiceHandler(KlarnaPayLaterPaymentMethodHandler::class));
        self::assertFalse(CaptureConfigurationMigrator::isOpenInvoiceHandler(CardsPaymentMethodHandler::class));
        self::assertFalse(CaptureConfigurationMigrator::isOpenInvoiceHandler(InvoicePayment::class));
    }

    private function givenConfig(array $values, ?string $salesChannelId = null): void
    {
        foreach ($values as $key => $value) {
            $this->store[$salesChannelId ?? ''][self::CONFIG . $key] = $value;
        }
    }

    private function write(string $key, $value, ?string $salesChannelId): void
    {
        $this->writes++;
        if ($value === null) {
            unset($this->store[$salesChannelId ?? ''][$key]);

            return;
        }

        $this->store[$salesChannelId ?? ''][$key] = $value;
    }

    private function config(string $key, ?string $salesChannelId = null)
    {
        return $this->store[$salesChannelId ?? ''][self::CONFIG . $key] ?? null;
    }

    private function paymentMethod(string $paymentMethodId, string $field, ?string $salesChannelId = null)
    {
        return $this->store[$salesChannelId ?? ''][self::PM . $paymentMethodId . '.' . $field] ?? null;
    }
}
