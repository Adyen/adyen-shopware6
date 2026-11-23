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
use Adyen\Shopware\Handlers\IdealPaymentMethodHandler;
use Adyen\Shopware\Handlers\KlarnaPayLaterPaymentMethodHandler;
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\Service\CaptureConfigurationResolver;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CaptureConfigurationResolverTest extends AdyenTestCase
{
    private const PAYMENT_METHOD_ID = 'pm-ratepay';
    private const SALES_CHANNEL_B = 'sales-channel-b';
    private const STATE_SHIPPED = 'state-shipped';
    private const STATE_DELIVERED = 'state-delivered';

    /**
     * @var array<string, array<string, mixed>> Stored config values per scope ('all' = All Sales Channels).
     */
    private array $config = [];

    private MockObject $paymentMethodRepository;
    private CaptureConfigurationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $systemConfigService = $this->getSimpleMock(SystemConfigService::class);
        // Same inheritance as SystemConfigService::get(): the sales channel value, otherwise All Sales Channels.
        $systemConfigService->method('get')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null): mixed {
                if ($salesChannelId !== null && array_key_exists($key, $this->config[$salesChannelId] ?? [])) {
                    return $this->config[$salesChannelId][$key];
                }

                return $this->config['all'][$key] ?? null;
            }
        );

        $this->paymentMethodRepository = $this->getSimpleMock(EntityRepository::class);

        $this->resolver = new CaptureConfigurationResolver(
            new ConfigurationService($systemConfigService, null),
            $this->paymentMethodRepository
        );
    }

    public function testNonAdyenHandlerIsAutomaticWithoutReadingItsFlags(): void
    {
        $this->setGlobal('captureMode', 'manual');

        self::assertFalse(CaptureConfigurationResolver::isAdyenHandler(InvoicePayment::class));
        self::assertFalse($this->resolver->supportsCaptureConfiguration(InvoicePayment::class));
        self::assertEquals(
            CaptureConfiguration::automatic(),
            $this->resolver->resolve(InvoicePayment::class, self::PAYMENT_METHOD_ID, null)
        );
    }

    public function testDeprecatedMissingAndEmptyHandlersAreAutomatic(): void
    {
        $this->setGlobal('captureMode', 'manual');

        foreach (['Adyen\Shopware\Handlers\DotpayPaymentMethodHandler', 'Not\A\Class', '', null] as $handler) {
            self::assertFalse($this->resolver->supportsCaptureConfiguration($handler));
            self::assertEquals(
                CaptureConfiguration::automatic(),
                $this->resolver->resolve($handler, self::PAYMENT_METHOD_ID, null)
            );
        }
    }

    public function testMethodWithoutManualCaptureSupportIsAlwaysAutomatic(): void
    {
        $this->setGlobal('captureMode', 'manual');
        $this->setPaymentMethod('mode', 'on_shipment');

        self::assertFalse($this->resolver->supportsCaptureConfiguration(IdealPaymentMethodHandler::class));
        self::assertEquals(
            CaptureConfiguration::automatic(),
            $this->resolver->resolve(IdealPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );
    }

    public function testDefaultsToImmediateWhenNothingIsConfigured(): void
    {
        self::assertTrue($this->resolver->supportsCaptureConfiguration(CardsPaymentMethodHandler::class));
        self::assertEquals(
            new CaptureConfiguration(CaptureMode::IMMEDIATE),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );
    }

    public function testMethodWithoutOwnModeFollowsTheGlobalMode(): void
    {
        $this->setGlobal('captureMode', 'delayed');
        $this->setGlobal('captureDelayDays', 2);

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::DELAYED, 2),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );
        self::assertEquals(
            new CaptureConfiguration(CaptureMode::DELAYED, 2),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, null, null)
        );
    }

    public function testExplicitGlobalModeFollowsTheGlobalMode(): void
    {
        $this->setGlobal('captureMode', 'manual');
        $this->setPaymentMethod('mode', 'global');

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::MANUAL),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );
    }

    public function testMethodModeOverridesTheGlobalMode(): void
    {
        $this->setGlobal('captureMode', 'immediate');
        $this->setPaymentMethod('mode', 'manual');

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::MANUAL),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );
    }

    /**
     * Spec §3.4: Ratepay is Manual in All Sales Channels and the global mode is Immediate.
     */
    public function testSalesChannelInheritanceAndExplicitGlobal(): void
    {
        $this->setGlobal('captureMode', 'immediate');
        $this->setPaymentMethod('mode', 'manual');

        // Not set in sales channel B: inherits the payment method value of All Sales Channels.
        self::assertSame(CaptureMode::MANUAL, $this->resolveMode(self::SALES_CHANNEL_B));

        // "Use global configuration" in sales channel B: the global mode, not the All Sales Channels method value.
        $this->setPaymentMethod('mode', 'global', self::SALES_CHANNEL_B);
        self::assertSame(CaptureMode::IMMEDIATE, $this->resolveMode(self::SALES_CHANNEL_B));

        // Own value in sales channel B.
        $this->setPaymentMethod('mode', 'delayed', self::SALES_CHANNEL_B);
        self::assertSame(CaptureMode::DELAYED, $this->resolveMode(self::SALES_CHANNEL_B));

        // All Sales Channels is unchanged.
        self::assertSame(CaptureMode::MANUAL, $this->resolveMode(null));
    }

    public function testGlobalModeOfTheSalesChannelIsUsed(): void
    {
        $this->setGlobal('captureMode', 'immediate');
        $this->setGlobal('captureMode', 'manual', self::SALES_CHANNEL_B);

        self::assertSame(CaptureMode::IMMEDIATE, $this->resolveMode(null));
        self::assertSame(CaptureMode::MANUAL, $this->resolveMode(self::SALES_CHANNEL_B));
    }

    public function testOwnModeUsesOwnParametersAndFallsBackToGlobalParameters(): void
    {
        $this->setGlobal('orderState', self::STATE_DELIVERED);
        $this->setGlobal('rescheduleTime', 43200);
        $this->setPaymentMethod('mode', 'on_shipment');

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_DELIVERED, 43200),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );

        $this->setPaymentMethod('orderState', self::STATE_SHIPPED);
        $this->setPaymentMethod('retryInterval', 600);

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_SHIPPED, 600),
            $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, null)
        );
    }

    public function testOwnDelayedModeUsesOwnOrGlobalDelay(): void
    {
        $this->setGlobal('captureDelayDays', 2);
        $this->setPaymentMethod('mode', 'delayed');

        self::assertSame(48, $this->resolve(null)->getCaptureDelayHours());

        $this->setPaymentMethod('delayDays', 5);
        self::assertSame(120, $this->resolve(null)->getCaptureDelayHours());
    }

    /**
     * Spec §4 step 4: parameters stored for the payment method from an earlier mode must not override the global
     * parameters while the payment method follows the global mode.
     */
    public function testGlobalModeIgnoresStoredMethodParameters(): void
    {
        $this->setGlobal('captureMode', 'on_shipment');
        $this->setGlobal('orderState', self::STATE_DELIVERED);
        $this->setGlobal('rescheduleTime', 43200);
        $this->setGlobal('captureDelayDays', 2);
        $this->setPaymentMethod('mode', 'global');
        $this->setPaymentMethod('orderState', self::STATE_SHIPPED);
        $this->setPaymentMethod('retryInterval', 600);
        $this->setPaymentMethod('delayDays', 5);

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_DELIVERED, 43200),
            $this->resolve(null)
        );

        $this->setGlobal('captureMode', 'delayed');
        self::assertSame(48, $this->resolve(null)->getCaptureDelayHours());
    }

    public function testInvalidValuesAreIgnored(): void
    {
        $this->setGlobal('captureMode', 'not-a-mode');
        $this->setGlobal('captureDelayDays', 3);
        $this->setPaymentMethod('mode', 'not-a-mode');

        self::assertSame(CaptureMode::IMMEDIATE, $this->resolveMode(null));

        $this->setPaymentMethod('mode', 'delayed');
        $this->setPaymentMethod('delayDays', 0);
        self::assertSame(3, $this->resolve(null)->delayDays);

        $this->setPaymentMethod('delayDays', 'abc');
        self::assertSame(3, $this->resolve(null)->delayDays);

        $this->setPaymentMethod('delayDays', '4');
        self::assertSame(4, $this->resolve(null)->delayDays);
    }

    /**
     * Review finding 6: values saved through the API or system:config:set are not validated by the admin, and Adyen
     * rejects a capture delay above 7 days.
     */
    public function testDelayAboveTheMaximumIsLimitedToTheMaximum(): void
    {
        $this->setGlobal('captureMode', 'delayed');
        $this->setGlobal('captureDelayDays', 30);

        self::assertSame(7, $this->resolve(null)->delayDays);
        self::assertSame(168, $this->resolve(null)->getCaptureDelayHours());

        $this->setGlobal('captureDelayDays', '7');
        self::assertSame(7, $this->resolve(null)->delayDays);

        $this->setPaymentMethod('mode', 'delayed');
        $this->setPaymentMethod('delayDays', '12');
        self::assertSame(7, $this->resolve(null)->delayDays);

        $this->setPaymentMethod('delayDays', 1);
        self::assertSame(1, $this->resolve(null)->delayDays);
    }

    public function testDelayBelowTheMinimumFallsBackToTheGlobalDelay(): void
    {
        $this->setGlobal('captureDelayDays', 2);
        $this->setPaymentMethod('mode', 'delayed');
        $this->setPaymentMethod('delayDays', -5);

        self::assertSame(2, $this->resolve(null)->delayDays);
    }

    public function testResolveByHandlerLooksUpThePaymentMethodOnce(): void
    {
        $this->setPaymentMethod('mode', 'manual');

        $idSearchResult = $this->getSimpleMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn(self::PAYMENT_METHOD_ID);
        $this->paymentMethodRepository->expects(self::once())->method('searchIds')->willReturn($idSearchResult);

        self::assertSame(
            CaptureMode::MANUAL,
            $this->resolver->resolveByHandler(CardsPaymentMethodHandler::class, null)->mode
        );
        self::assertSame(
            CaptureMode::MANUAL,
            $this->resolver->resolveByHandler(CardsPaymentMethodHandler::class, null)->mode
        );
    }

    public function testResolveByHandlerWithoutManualCaptureSupportDoesNotQuery(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('searchIds');

        self::assertEquals(
            CaptureConfiguration::automatic(),
            $this->resolver->resolveByHandler(IdealPaymentMethodHandler::class, null)
        );
    }

    public function testResolveForTransactionPrefersTheSnapshot(): void
    {
        $this->setGlobal('captureMode', 'immediate');
        $snapshot = new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_SHIPPED, 600);

        $transaction = $this->createTransaction(KlarnaPayLaterPaymentMethodHandler::class, [
            CaptureConfigurationResolver::CUSTOM_FIELD_KEY => $snapshot->toArray(),
        ]);

        self::assertEquals($snapshot, $this->resolver->getSnapshot($transaction));
        self::assertEquals($snapshot, $this->resolver->resolveForTransaction($transaction, self::SALES_CHANNEL_B));
    }

    public function testResolveForTransactionWithoutSnapshotUsesTheCurrentConfiguration(): void
    {
        $this->setGlobal('captureMode', 'immediate');
        $this->setPaymentMethod('mode', 'manual', self::SALES_CHANNEL_B);

        $transaction = $this->createTransaction(CardsPaymentMethodHandler::class, ['other' => 'value']);

        self::assertNull($this->resolver->getSnapshot($transaction));
        self::assertSame(
            CaptureMode::MANUAL,
            $this->resolver->resolveForTransaction($transaction, self::SALES_CHANNEL_B)->mode
        );
        self::assertSame(CaptureMode::IMMEDIATE, $this->resolver->resolveForTransaction($transaction, null)->mode);
    }

    /**
     * Review finding 4: a card payment authorised with auto capture before the upgrade, whose AUTHORISATION webhook
     * is processed after the merchant switched cards to manual, was already captured by Adyen.
     */
    public function testTransactionCreatedBeforeTheMigrationKeepsTheOldAutomaticCapture(): void
    {
        $this->givenMigration(['mode' => 'immediate']);
        $this->setGlobal('captureMode', 'manual');
        $this->setPaymentMethod('mode', 'manual');

        $transaction = $this->createTransaction(CardsPaymentMethodHandler::class, [], '-1 day');

        self::assertEquals(
            CaptureConfiguration::automatic(),
            $this->resolver->resolveForTransaction($transaction, null)
        );
    }

    public function testTransactionCreatedBeforeTheMigrationKeepsTheOldManualCapture(): void
    {
        $this->givenMigration(['mode' => 'manual']);
        $this->setGlobal('captureMode', 'immediate');

        $transaction = $this->createTransaction(CardsPaymentMethodHandler::class, [], '-1 day');

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::MANUAL),
            $this->resolver->resolveForTransaction($transaction, null)
        );
    }

    public function testOldManualCaptureOnlyAppliedToMethodsThatSupportIt(): void
    {
        $this->givenMigration(['mode' => 'manual']);

        $transaction = $this->createTransaction(IdealPaymentMethodHandler::class, [], '-1 day');

        self::assertEquals(
            CaptureConfiguration::automatic(),
            $this->resolver->resolveForTransaction($transaction, null)
        );
    }

    public function testOldOpenInvoiceCaptureOnShipmentUsesTheOldGlobalStateAndInterval(): void
    {
        $this->givenMigration([
            'mode' => 'immediate',
            'openInvoiceMode' => 'on_shipment',
            'orderState' => self::STATE_SHIPPED,
            'retryInterval' => 600,
        ]);
        // Changed by the merchant after the migration.
        $this->setGlobal('orderState', self::STATE_DELIVERED);
        $this->setPaymentMethod('mode', 'immediate');

        $transaction = $this->createTransaction(KlarnaPayLaterPaymentMethodHandler::class, [], '-1 day');

        self::assertEquals(
            new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, self::STATE_SHIPPED, 600),
            $this->resolver->resolveForTransaction($transaction, null)
        );
    }

    public function testOldOpenInvoiceAutoCaptureAndDefault(): void
    {
        $transaction = $this->createTransaction(KlarnaPayLaterPaymentMethodHandler::class, [], '-1 day');

        $this->givenMigration(['mode' => 'manual', 'openInvoiceMode' => 'immediate']);
        self::assertEquals(
            CaptureConfiguration::automatic(),
            $this->resolver->resolveForTransaction($transaction, null)
        );

        // Open invoice payment methods were manual capture unless auto capture was enabled.
        $this->config = [];
        $this->givenMigration(['mode' => 'immediate']);
        self::assertEquals(
            new CaptureConfiguration(CaptureMode::MANUAL),
            $this->resolver->resolveForTransaction($transaction, null)
        );
    }

    public function testOldCaptureBehaviorOfTheSalesChannelIsUsed(): void
    {
        $this->givenMigration(['mode' => 'immediate']);
        $this->config[self::SALES_CHANNEL_B][ConfigurationService::CAPTURE_LEGACY_PREFIX . 'mode'] = 'manual';

        $transaction = $this->createTransaction(CardsPaymentMethodHandler::class, [], '-1 day');

        self::assertSame(
            CaptureMode::MANUAL,
            $this->resolver->resolveForTransaction($transaction, self::SALES_CHANNEL_B)->mode
        );
        self::assertSame(CaptureMode::IMMEDIATE, $this->resolver->resolveForTransaction($transaction, null)->mode);
    }

    public function testTransactionCreatedAfterTheMigrationWithoutSnapshotUsesTheCurrentConfiguration(): void
    {
        $this->givenMigration(['mode' => 'immediate'], '-1 day');
        $this->setPaymentMethod('mode', 'manual');

        $transaction = $this->createTransaction(CardsPaymentMethodHandler::class, [], '-1 hour');

        self::assertSame(CaptureMode::MANUAL, $this->resolver->resolveForTransaction($transaction, null)->mode);
    }

    public function testSnapshotIsPreferredOverTheOldCaptureBehavior(): void
    {
        $this->givenMigration(['mode' => 'immediate']);
        $snapshot = new CaptureConfiguration(CaptureMode::DELAYED, 2);

        $transaction = $this->createTransaction(
            CardsPaymentMethodHandler::class,
            [CaptureConfigurationResolver::CUSTOM_FIELD_KEY => $snapshot->toArray()],
            '-1 day'
        );

        self::assertEquals($snapshot, $this->resolver->resolveForTransaction($transaction, null));
    }

    public function testInvalidMigrationTimeIsIgnored(): void
    {
        $this->givenMigration(['mode' => 'immediate']);
        $this->config['all'][ConfigurationService::CAPTURE_LEGACY_PREFIX . 'migratedAt'] = 'not a date';
        $this->setPaymentMethod('mode', 'manual');

        $transaction = $this->createTransaction(CardsPaymentMethodHandler::class, [], '-1 day');

        self::assertSame(CaptureMode::MANUAL, $this->resolver->resolveForTransaction($transaction, null)->mode);
    }

    public function testResolveForTransactionLoadsTheHandlerWhenThePaymentMethodIsNotLoaded(): void
    {
        $this->setPaymentMethod('mode', 'manual');

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(self::PAYMENT_METHOD_ID);
        $paymentMethod->setHandlerIdentifier(CardsPaymentMethodHandler::class);
        $searchResult = $this->getSimpleMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($paymentMethod);
        $this->paymentMethodRepository->expects(self::once())->method('search')->willReturn($searchResult);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');
        $transaction->setPaymentMethodId(self::PAYMENT_METHOD_ID);

        self::assertSame(CaptureMode::MANUAL, $this->resolver->resolveForTransaction($transaction, null)->mode);
    }

    /**
     * Old capture behavior as stored by the migration, which ran at $migratedAt.
     */
    private function givenMigration(array $legacyValues, string $migratedAt = 'now'): void
    {
        $legacyValues['migratedAt'] = (new \DateTimeImmutable($migratedAt))->format(\DATE_ATOM);
        foreach ($legacyValues as $field => $value) {
            $this->config['all'][ConfigurationService::CAPTURE_LEGACY_PREFIX . $field] = $value;
        }
    }

    private function setGlobal(string $field, mixed $value, ?string $salesChannelId = null): void
    {
        $this->config[$salesChannelId ?? 'all']['AdyenPaymentShopware6.config.' . $field] = $value;
    }

    private function setPaymentMethod(string $field, mixed $value, ?string $salesChannelId = null): void
    {
        $key = ConfigurationService::getPaymentMethodCaptureKey(self::PAYMENT_METHOD_ID, $field);
        $this->config[$salesChannelId ?? 'all'][$key] = $value;
    }

    private function resolve(?string $salesChannelId): CaptureConfiguration
    {
        return $this->resolver->resolve(CardsPaymentMethodHandler::class, self::PAYMENT_METHOD_ID, $salesChannelId);
    }

    private function resolveMode(?string $salesChannelId): CaptureMode
    {
        return $this->resolve($salesChannelId)->mode;
    }

    private function createTransaction(
        string $handlerIdentifier,
        array $customFields,
        ?string $createdAt = null
    ): OrderTransactionEntity {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(self::PAYMENT_METHOD_ID);
        $paymentMethod->setHandlerIdentifier($handlerIdentifier);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');
        $transaction->setPaymentMethodId(self::PAYMENT_METHOD_ID);
        $transaction->setPaymentMethod($paymentMethod);
        $transaction->setCustomFields($customFields);
        if ($createdAt !== null) {
            $transaction->setCreatedAt(new \DateTimeImmutable($createdAt));
        }

        return $transaction;
    }
}
