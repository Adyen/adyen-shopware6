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

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\Util\ConfigValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Moves the old group-based capture settings to the new capture configuration (spec §9).
 *
 * Runs once per shop, from install() and as the last step of update(). Every read uses getDomain(), never get():
 * SystemConfigService::get() hides all keys of an inactive plugin, and the plugin is always inactive during
 * install(). Not a container service: update() runs with the container of the previous plugin version.
 */
class CaptureConfigurationMigrator
{
    public const MIGRATED_KEY = ConfigurationService::CAPTURE_CONFIGURATION_PREFIX . 'migrated';

    private const CONFIG_DOMAIN = ConfigurationService::BUNDLE_NAME . '.config';
    private const CONFIG_PREFIX = self::CONFIG_DOMAIN . '.';

    private const OLD_MANUAL_CAPTURE = 'manualCaptureEnabled';
    private const OLD_AUTO_CAPTURE_OPEN_INVOICE = 'autoCaptureOpenInvoice';
    private const OLD_CAPTURE_ON_SHIPMENT = 'captureOnShipmentEnabled';
    private const OLD_BOOLEAN_KEYS = [
        self::OLD_MANUAL_CAPTURE,
        self::OLD_AUTO_CAPTURE_OPEN_INVOICE,
        self::OLD_CAPTURE_ON_SHIPMENT,
    ];

    private const ORDER_STATE = 'orderState';
    private const RESCHEDULE_TIME = 'rescheduleTime';
    private const DEFAULT_RESCHEDULE_TIME = 43200;

    /**
     * @var Connection $connection
     */
    private Connection $connection;

    /**
     * @var SystemConfigService $systemConfigService
     */
    private SystemConfigService $systemConfigService;

    /**
     * @param Connection $connection
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(Connection $connection, SystemConfigService $systemConfigService)
    {
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @return bool
     */
    public function isMigrated(): bool
    {
        $values = $this->systemConfigService->getDomain(rtrim(ConfigurationService::CAPTURE_CONFIGURATION_PREFIX, '.'));

        return !empty($values[self::MIGRATED_KEY]);
    }

    /**
     * @return void
     */
    public function migrate(): void
    {
        if ($this->isMigrated()) {
            return;
        }

        // The old keys are deleted and the migrated flag is set in the same transaction as the new values. If it
        // fails halfway, nothing is changed and the next update runs the migration again on the original values.
        $this->connection->transactional(function (): void {
            $this->migrateConfiguration();
        });
    }

    /**
     * @return void
     */
    private function migrateConfiguration(): void
    {

        $openInvoicePaymentMethodIds = $this->getOpenInvoicePaymentMethodIds();

        // Only the explicitly stored values of each scope; a missing key inherits.
        // Scope key '' is All Sales Channels.
        $globalValues = $this->getOwnConfigValues(null);
        $scopes = ['' => $globalValues];
        foreach ($this->getSalesChannelIdsWithOldValues() as $salesChannelId) {
            $scopes[$salesChannelId] = $this->getOwnConfigValues($salesChannelId);
        }

        // The old code read the delivery state and the retry interval only globally.
        $globalOrderState = ConfigValue::toNonEmptyString(
            $globalValues[self::CONFIG_PREFIX . self::ORDER_STATE] ?? null
        );

        // Default delivery state "Shipped". It cannot be a config.xml default, because its id differs per shop.
        if ($globalOrderState === null) {
            $globalOrderState = $this->getShippedDeliveryStateId();
            if ($globalOrderState !== null) {
                $this->systemConfigService->set(self::CONFIG_PREFIX . self::ORDER_STATE, $globalOrderState);
            }
        }
        $globalRescheduleTime = ConfigValue::toPositiveInt(
            $globalValues[self::CONFIG_PREFIX . self::RESCHEDULE_TIME] ?? null
        ) ?? self::DEFAULT_RESCHEDULE_TIME;

        foreach ($scopes as $scopeKey => $ownValues) {
            $salesChannelId = $scopeKey === '' ? null : (string)$scopeKey;
            $isGlobal = $salesChannelId === null;
            $values = [];

            if ($isGlobal || $this->hasOwnValue($ownValues, self::OLD_MANUAL_CAPTURE)) {
                $values[self::CONFIG_PREFIX . 'captureMode'] = $this->getOldValue(
                    self::OLD_MANUAL_CAPTURE,
                    $ownValues,
                    $globalValues
                ) ? CaptureMode::MANUAL->value : CaptureMode::IMMEDIATE->value;
                $values[self::getLegacyKey(ConfigurationService::CAPTURE_LEGACY_FIELD_MODE)] =
                    $values[self::CONFIG_PREFIX . 'captureMode'];
            }

            if ($isGlobal
                || $this->hasOwnValue($ownValues, self::OLD_AUTO_CAPTURE_OPEN_INVOICE)
                || $this->hasOwnValue($ownValues, self::OLD_CAPTURE_ON_SHIPMENT)
            ) {
                $mode = $this->getOpenInvoiceMode($ownValues, $globalValues);
                $values[self::getLegacyKey(ConfigurationService::CAPTURE_LEGACY_FIELD_OPEN_INVOICE_MODE)] =
                    $mode->value;

                foreach ($openInvoicePaymentMethodIds as $paymentMethodId) {
                    $values += $this->getPaymentMethodValues(
                        $paymentMethodId,
                        $mode,
                        $globalOrderState,
                        $globalRescheduleTime
                    );
                }
            }

            if ($values !== []) {
                $this->systemConfigService->setMultiple($values, $salesChannelId);
            }
        }

        // The old settings are no longer in config.xml. Null deletes the key.
        foreach ($scopes as $scopeKey => $ownValues) {
            $salesChannelId = $scopeKey === '' ? null : (string)$scopeKey;
            $obsolete = [];
            foreach (self::OLD_BOOLEAN_KEYS as $key) {
                if ($this->hasOwnValue($ownValues, $key)) {
                    $obsolete[self::CONFIG_PREFIX . $key] = null;
                }
            }

            if ($obsolete !== []) {
                $this->systemConfigService->setMultiple($obsolete, $salesChannelId);
            }
        }

        // The old code read the delivery state and the retry interval only globally.
        $this->systemConfigService->setMultiple([
            self::getLegacyKey(ConfigurationService::CAPTURE_LEGACY_FIELD_ORDER_STATE) => $globalOrderState,
            self::getLegacyKey(ConfigurationService::CAPTURE_LEGACY_FIELD_RETRY_INTERVAL) => $globalRescheduleTime,
            self::getLegacyKey(ConfigurationService::CAPTURE_LEGACY_FIELD_MIGRATED_AT) =>
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ]);

        $this->systemConfigService->set(self::MIGRATED_KEY, true);
    }

    /**
     * @param string $field One of the ConfigurationService::CAPTURE_LEGACY_FIELD_* constants.
     *
     * @return string
     */
    private static function getLegacyKey(string $field): string
    {
        return ConfigurationService::CAPTURE_LEGACY_PREFIX . $field;
    }

    /**
     * Open invoice payment methods added by a later plugin version keep the open invoice default: manual capture
     * (spec §9.4). Before the migration has run, the migration sets it.
     *
     * @param string $paymentMethodId
     *
     * @return void
     */
    public function applyNewOpenInvoiceDefault(string $paymentMethodId): void
    {
        if (!$this->isMigrated()) {
            return;
        }

        $this->systemConfigService->set(
            ConfigurationService::getPaymentMethodCaptureKey(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE
            ),
            CaptureMode::MANUAL->value
        );
    }

    /**
     * @param string $handlerIdentifier
     *
     * @return bool
     */
    public static function isOpenInvoiceHandler(string $handlerIdentifier): bool
    {
        if (!CaptureConfigurationResolver::isAdyenHandler($handlerIdentifier)) {
            return false;
        }

        /** @var class-string<\Adyen\Shopware\Handlers\AbstractPaymentMethodHandler> $handlerIdentifier */
        return $handlerIdentifier::$isOpenInvoice;
    }

    /**
     * @param array<string, mixed> $ownValues
     * @param array<string, mixed> $globalValues
     *
     * @return CaptureMode
     */
    private function getOpenInvoiceMode(array $ownValues, array $globalValues): CaptureMode
    {
        if ($this->getOldValue(self::OLD_AUTO_CAPTURE_OPEN_INVOICE, $ownValues, $globalValues)) {
            return CaptureMode::IMMEDIATE;
        }

        return $this->getOldValue(self::OLD_CAPTURE_ON_SHIPMENT, $ownValues, $globalValues)
            ? CaptureMode::ON_SHIPMENT
            : CaptureMode::MANUAL;
    }

    /**
     * @param string $paymentMethodId
     * @param CaptureMode $mode
     * @param string|null $orderState
     * @param int $rescheduleTime
     *
     * @return array<string, mixed>
     */
    private function getPaymentMethodValues(
        string $paymentMethodId,
        CaptureMode $mode,
        ?string $orderState,
        int $rescheduleTime
    ): array {
        $values = [
            ConfigurationService::getPaymentMethodCaptureKey(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE
            ) => $mode->value,
        ];

        if ($mode === CaptureMode::ON_SHIPMENT) {
            if ($orderState !== null) {
                $values[ConfigurationService::getPaymentMethodCaptureKey(
                    $paymentMethodId,
                    ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE
                )] = $orderState;
            }

            $values[ConfigurationService::getPaymentMethodCaptureKey(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL
            )] = $rescheduleTime;
        }

        return $values;
    }

    /**
     * Effective old value: the scope's own value, otherwise the global value, otherwise false.
     *
     * @param string $key
     * @param array<string, mixed> $ownValues
     * @param array<string, mixed> $globalValues
     *
     * @return bool
     */
    private function getOldValue(string $key, array $ownValues, array $globalValues): bool
    {
        $fullKey = self::CONFIG_PREFIX . $key;

        return (bool)($ownValues[$fullKey] ?? $globalValues[$fullKey] ?? false);
    }

    /**
     * @param array<string, mixed> $ownValues
     * @param string $key
     *
     * @return bool
     */
    private function hasOwnValue(array $ownValues, string $key): bool
    {
        return array_key_exists(self::CONFIG_PREFIX . $key, $ownValues)
            && $ownValues[self::CONFIG_PREFIX . $key] !== null;
    }

    /**
     * @param string|null $salesChannelId
     *
     * @return array<string, mixed>
     */
    private function getOwnConfigValues(?string $salesChannelId): array
    {
        return $this->systemConfigService->getDomain(self::CONFIG_DOMAIN, $salesChannelId);
    }

    /**
     * @return string|null Id of the "shipped" state of the order delivery state machine.
     */
    private function getShippedDeliveryStateId(): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(state.id)) FROM state_machine_state state
             INNER JOIN state_machine machine ON machine.id = state.state_machine_id
             WHERE machine.technical_name = :stateMachine AND state.technical_name = :state',
            ['stateMachine' => 'order_delivery.state', 'state' => 'shipped']
        );

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<string>
     */
    private function getSalesChannelIdsWithOldValues(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(sales_channel_id)) FROM system_config
             WHERE sales_channel_id IS NOT NULL AND configuration_key IN (:keys)',
            [
                'keys' => array_map(
                    static fn (string $key): string => self::CONFIG_PREFIX . $key,
                    self::OLD_BOOLEAN_KEYS
                ),
            ],
            ['keys' => ArrayParameterType::STRING]
        );
    }

    /**
     * Installed payment methods of this plugin whose handler is an open invoice handler.
     *
     * @return list<string>
     */
    private function getOpenInvoicePaymentMethodIds(): array
    {
        $handlers = [];
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethodClass) {
            $paymentMethod = new $paymentMethodClass();
            if (self::isOpenInvoiceHandler($paymentMethod->getPaymentHandler())) {
                $handlers[] = $paymentMethod->getPaymentHandler();
            }
        }

        if ($handlers === []) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM payment_method WHERE handler_identifier IN (:handlers)',
            ['handlers' => $handlers],
            ['handlers' => ArrayParameterType::STRING]
        );
    }
}
