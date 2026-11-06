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

use Adyen\Shopware\Exception\CaptureConfigurationValidationException;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\Util\ConfigValue;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads and writes the capture configuration of one payment method for the administration (spec §10.2, §10.3).
 */
class CaptureConfigurationService
{
    public const MIN_DELAY_DAYS = 1;
    public const MAX_DELAY_DAYS = 7;

    private const FIELDS = [
        ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE,
        ConfigurationService::CAPTURE_CONFIGURATION_FIELD_DELAY_DAYS,
        ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE,
        ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL,
    ];

    /**
     * @var SystemConfigService $systemConfigService
     */
    private SystemConfigService $systemConfigService;

    /**
     * @var ConfigurationService $configurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var CaptureConfigurationResolver $resolver
     */
    private CaptureConfigurationResolver $resolver;

    /**
     * @var EntityRepository $paymentMethodRepository
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var Connection $connection
     */
    private Connection $connection;

    /**
     * @param SystemConfigService $systemConfigService
     * @param ConfigurationService $configurationService
     * @param CaptureConfigurationResolver $resolver
     * @param EntityRepository $paymentMethodRepository
     * @param Connection $connection
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        ConfigurationService $configurationService,
        CaptureConfigurationResolver $resolver,
        EntityRepository $paymentMethodRepository,
        Connection $connection
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->configurationService = $configurationService;
        $this->resolver = $resolver;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->connection = $connection;
    }

    /**
     * @param string $paymentMethodId
     * @param string|null $salesChannelId Null for All Sales Channels.
     * @param Context $context
     *
     * @return array{
     *     supported: bool,
     *     values?: array<string, mixed>,
     *     inherited?: array<string, mixed>,
     *     globalMode?: string
     * }
     */
    public function getForAdmin(string $paymentMethodId, ?string $salesChannelId, Context $context): array
    {
        $handlerIdentifier = $this->getSupportedHandler($paymentMethodId, $context);
        if ($handlerIdentifier === null) {
            return ['supported' => false];
        }

        $values = $this->getScopeValues($paymentMethodId, $salesChannelId);
        $allSalesChannelsValues = $salesChannelId === null
            ? []
            : $this->getScopeValues($paymentMethodId, null);

        // What applies to a field that is empty in this scope.
        $inherited = [
            ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE =>
                $allSalesChannelsValues[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE]
                ?? CaptureMode::GLOBAL->value,
            ConfigurationService::CAPTURE_CONFIGURATION_FIELD_DELAY_DAYS =>
                $allSalesChannelsValues[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_DELAY_DAYS]
                ?? $this->configurationService->getCaptureDelayDays($salesChannelId),
            ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE =>
                $allSalesChannelsValues[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE]
                ?? $this->configurationService->getOrderState($salesChannelId),
            ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL =>
                $allSalesChannelsValues[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL]
                ?? $this->configurationService->getRescheduleTime($salesChannelId),
        ];

        return [
            'supported' => true,
            'values' => $values,
            'inherited' => $inherited,
            // The global capture mode of this scope, for "Use global configuration".
            'globalMode' => $this->resolver->resolve($handlerIdentifier, null, $salesChannelId)->mode->value,
        ];
    }

    /**
     * @param string $paymentMethodId
     * @param string|null $salesChannelId Null for All Sales Channels.
     * @param array<string, mixed> $values Field => value; null removes the value of this scope (inherit).
     * @param Context $context
     *
     * @return void
     *
     * @throws CaptureConfigurationValidationException
     */
    public function save(string $paymentMethodId, ?string $salesChannelId, array $values, Context $context): void
    {
        if ($this->getSupportedHandler($paymentMethodId, $context) === null) {
            throw new CaptureConfigurationValidationException(
                'The payment method does not support a capture configuration.'
            );
        }

        if ($salesChannelId !== null && !Uuid::isValid($salesChannelId)) {
            throw new CaptureConfigurationValidationException('Invalid sales channel.');
        }

        $normalized = $this->validate($values);

        // The values after saving, with inheritance: the mode must have the parameters it needs.
        $mode = CaptureMode::tryFromConfig($normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE]);
        if ($mode === CaptureMode::ON_SHIPMENT) {
            $orderState = $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE]
                ?? ($salesChannelId === null ? null : $this->getScopeValues($paymentMethodId, null)[
                    ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE
                ] ?? null)
                ?? $this->configurationService->getOrderState($salesChannelId);

            if (empty($orderState)) {
                throw new CaptureConfigurationValidationException(
                    'Select an order shipping state for triggering capture when Capture on shipment is selected.'
                );
            }
        }

        $config = [];
        foreach ($normalized as $field => $value) {
            $config[ConfigurationService::getPaymentMethodCaptureKey($paymentMethodId, $field)] = $value;
        }

        $this->systemConfigService->setMultiple($config, $salesChannelId);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> Every field, null for empty values.
     *
     * @throws CaptureConfigurationValidationException
     */
    private function validate(array $values): array
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            $normalized[$field] = $value === '' ? null : $value;
        }

        $mode = $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE];
        if ($mode !== null && CaptureMode::tryFromConfig($mode) === null) {
            throw new CaptureConfigurationValidationException('Invalid capture mode.');
        }

        $delayDays = $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_DELAY_DAYS];
        if ($delayDays !== null) {
            if (!ConfigValue::isWholeNumber($delayDays)
                || (int)$delayDays < self::MIN_DELAY_DAYS
                || (int)$delayDays > self::MAX_DELAY_DAYS
            ) {
                throw new CaptureConfigurationValidationException(
                    'Capture delay (days) must be a whole number between 1 and 7.'
                );
            }
            $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_DELAY_DAYS] = (int)$delayDays;
        }

        $retryInterval = $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL];
        if ($retryInterval !== null) {
            if (!ConfigValue::isWholeNumber($retryInterval) || (int)$retryInterval < 1) {
                throw new CaptureConfigurationValidationException(
                    'Capture retry interval (seconds) must be a whole number of at least 1.'
                );
            }
            $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL] = (int)$retryInterval;
        }

        $orderState = $normalized[ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE];
        if ($orderState !== null && (!is_string($orderState) || !$this->isDeliveryState($orderState))) {
            throw new CaptureConfigurationValidationException('Invalid order shipping state.');
        }

        return $normalized;
    }

    /**
     * Values stored in exactly this scope. getDomain() without inheritance; get() always inherits.
     *
     * @param string $paymentMethodId
     * @param string|null $salesChannelId
     *
     * @return array<string, mixed> Field => value, null if not set in this scope.
     */
    private function getScopeValues(string $paymentMethodId, ?string $salesChannelId): array
    {
        $prefix = ConfigurationService::CAPTURE_CONFIGURATION_PREFIX . $paymentMethodId;
        $stored = $this->systemConfigService->getDomain($prefix, $salesChannelId);

        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $stored[ConfigurationService::getPaymentMethodCaptureKey($paymentMethodId, $field)]
                ?? null;
        }

        return $values;
    }

    /**
     * @param string $paymentMethodId
     * @param Context $context
     *
     * @return string|null The handler of the payment method if it belongs to this plugin and supports separate
     *     authorisation and capture.
     */
    private function getSupportedHandler(string $paymentMethodId, Context $context): ?string
    {
        if (!Uuid::isValid($paymentMethodId)) {
            return null;
        }

        $paymentMethod = $this->paymentMethodRepository->search(new Criteria([$paymentMethodId]), $context)->first();
        if (!$paymentMethod instanceof PaymentMethodEntity) {
            return null;
        }

        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

        return $this->resolver->supportsCaptureConfiguration($handlerIdentifier) ? $handlerIdentifier : null;
    }

    /**
     * @param string $stateId
     *
     * @return bool
     */
    private function isDeliveryState(string $stateId): bool
    {
        if (!Uuid::isValid($stateId)) {
            return false;
        }

        return (bool)$this->connection->fetchOne(
            'SELECT 1 FROM state_machine_state state
             INNER JOIN state_machine machine ON machine.id = state.state_machine_id
             WHERE state.id = :id AND machine.technical_name = :stateMachine',
            ['id' => Uuid::fromHexToBytes($stateId), 'stateMachine' => 'order_delivery.state']
        );
    }
}
