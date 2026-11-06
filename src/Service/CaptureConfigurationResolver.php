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

use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\Util\ConfigValue;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Resolves the effective capture configuration of a payment method in a sales channel.
 *
 * Order of precedence (spec §4): the payment method's own mode (sales channel, then All Sales Channels), otherwise
 * the global capture mode (sales channel, then All Sales Channels). SystemConfigService::get() already falls back
 * from the sales channel to All Sales Channels.
 */
class CaptureConfigurationResolver
{
    /**
     * Order transaction custom field that holds the configuration used when the payment was created.
     */
    public const CUSTOM_FIELD_KEY = 'adyenCaptureConfiguration';

    /**
     * @var ConfigurationService $configurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var EntityRepository $paymentMethodRepository
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var array<string, string|null> $paymentMethodIds Payment method ids by handler identifier.
     */
    private array $paymentMethodIds = [];

    /**
     * @var array<string, string|null> $handlerIdentifiers Handler identifiers by payment method id.
     */
    private array $handlerIdentifiers = [];

    /**
     * @param ConfigurationService $configurationService
     * @param EntityRepository $paymentMethodRepository
     */
    public function __construct(
        ConfigurationService $configurationService,
        EntityRepository $paymentMethodRepository
    ) {
        $this->configurationService = $configurationService;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     * Only Adyen handlers declare the capture flags ($supportsManualCapture, $isOpenInvoice), so they must not be
     * read before this check.
     *
     * @param string|null $handlerIdentifier
     *
     * @return bool
     */
    public static function isAdyenHandler(?string $handlerIdentifier): bool
    {
        return $handlerIdentifier !== null
            && $handlerIdentifier !== ''
            && !in_array($handlerIdentifier, PaymentMethods::DEPRECATED_PAYMENT_METHOD_HANDLERS, true)
            && is_a($handlerIdentifier, AbstractPaymentMethodHandler::class, true);
    }

    /**
     * @param string|null $handlerIdentifier
     *
     * @return bool True if the payment method can be configured (it supports separate authorisation and capture).
     */
    public function supportsCaptureConfiguration(?string $handlerIdentifier): bool
    {
        if (!self::isAdyenHandler($handlerIdentifier)) {
            return false;
        }

        /** @var class-string<AbstractPaymentMethodHandler> $handlerIdentifier */
        return $handlerIdentifier::$supportsManualCapture;
    }

    /**
     * @param string|null $handlerIdentifier
     * @param string|null $paymentMethodId Null if the payment method is not known; only the global mode applies.
     * @param string|null $salesChannelId
     *
     * @return CaptureConfiguration
     */
    public function resolve(
        ?string $handlerIdentifier,
        ?string $paymentMethodId,
        ?string $salesChannelId
    ): CaptureConfiguration {
        if (!$this->supportsCaptureConfiguration($handlerIdentifier)) {
            return CaptureConfiguration::automatic();
        }

        $paymentMethodMode = $paymentMethodId === null ? null : CaptureMode::tryFromConfig(
            $this->getPaymentMethodValue(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_MODE,
                $salesChannelId
            )
        );

        if ($paymentMethodMode === null || $paymentMethodMode === CaptureMode::GLOBAL) {
            return $this->resolveGlobal($salesChannelId);
        }

        // The payment method has its own mode: its own parameters, otherwise the global ones.
        return new CaptureConfiguration(
            $paymentMethodMode,
            ConfigValue::toPositiveInt($this->getPaymentMethodValue(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_DELAY_DAYS,
                $salesChannelId
            )) ?? $this->getGlobalDelayDays($salesChannelId),
            ConfigValue::toNonEmptyString($this->getPaymentMethodValue(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_ORDER_STATE,
                $salesChannelId
            )) ?? $this->getGlobalOrderStateId($salesChannelId),
            ConfigValue::toPositiveInt($this->getPaymentMethodValue(
                $paymentMethodId,
                ConfigurationService::CAPTURE_CONFIGURATION_FIELD_RETRY_INTERVAL,
                $salesChannelId
            )) ?? $this->getGlobalRetryIntervalSeconds($salesChannelId)
        );
    }

    /**
     * For flows where only the handler class is known (PayPal before the order exists).
     *
     * @param string $handlerIdentifier
     * @param string|null $salesChannelId
     *
     * @return CaptureConfiguration
     */
    public function resolveByHandler(string $handlerIdentifier, ?string $salesChannelId): CaptureConfiguration
    {
        if (!$this->supportsCaptureConfiguration($handlerIdentifier)) {
            return CaptureConfiguration::automatic();
        }

        return $this->resolve(
            $handlerIdentifier,
            $this->getPaymentMethodIdByHandler($handlerIdentifier),
            $salesChannelId
        );
    }

    /**
     * The configuration stored when the payment was created; the current configuration for older transactions.
     *
     * @param OrderTransactionEntity $transaction
     * @param string|null $salesChannelId
     *
     * @return CaptureConfiguration
     */
    public function resolveForTransaction(
        OrderTransactionEntity $transaction,
        ?string $salesChannelId
    ): CaptureConfiguration {
        $snapshot = $this->getSnapshot($transaction);
        if ($snapshot !== null) {
            return $snapshot;
        }

        $paymentMethodId = $transaction->getPaymentMethodId();
        $handlerIdentifier = $transaction->getPaymentMethod()?->getHandlerIdentifier()
            ?? $this->getHandlerIdentifierByPaymentMethodId($paymentMethodId);

        return $this->resolve($handlerIdentifier, $paymentMethodId, $salesChannelId);
    }

    /**
     * @param OrderTransactionEntity $transaction
     *
     * @return CaptureConfiguration|null
     */
    public function getSnapshot(OrderTransactionEntity $transaction): ?CaptureConfiguration
    {
        return CaptureConfiguration::fromArray($transaction->getCustomFields()[self::CUSTOM_FIELD_KEY] ?? null);
    }

    /**
     * Global mode with global parameters. Parameters stored for the payment method are ignored here, because the
     * payment method follows the global configuration (spec §4 step 4).
     *
     * @param string|null $salesChannelId
     *
     * @return CaptureConfiguration
     */
    private function resolveGlobal(?string $salesChannelId): CaptureConfiguration
    {
        $mode = CaptureMode::tryFromConfig($this->configurationService->getCaptureMode($salesChannelId));
        if ($mode === null || $mode === CaptureMode::GLOBAL) {
            $mode = CaptureMode::IMMEDIATE;
        }

        return new CaptureConfiguration(
            $mode,
            $this->getGlobalDelayDays($salesChannelId),
            $this->getGlobalOrderStateId($salesChannelId),
            $this->getGlobalRetryIntervalSeconds($salesChannelId)
        );
    }

    /**
     * @param string $paymentMethodId
     * @param string $field
     * @param string|null $salesChannelId
     *
     * @return mixed
     */
    private function getPaymentMethodValue(string $paymentMethodId, string $field, ?string $salesChannelId): mixed
    {
        return $this->configurationService->getPaymentMethodCaptureValue($paymentMethodId, $field, $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     *
     * @return int|null
     */
    private function getGlobalDelayDays(?string $salesChannelId): ?int
    {
        return ConfigValue::toPositiveInt($this->configurationService->getCaptureDelayDays($salesChannelId));
    }

    /**
     * @param string|null $salesChannelId
     *
     * @return string|null
     */
    private function getGlobalOrderStateId(?string $salesChannelId): ?string
    {
        return ConfigValue::toNonEmptyString($this->configurationService->getOrderState($salesChannelId));
    }

    /**
     * @param string|null $salesChannelId
     *
     * @return int|null
     */
    private function getGlobalRetryIntervalSeconds(?string $salesChannelId): ?int
    {
        return ConfigValue::toPositiveInt($this->configurationService->getRescheduleTime($salesChannelId));
    }

    /**
     * @param string $handlerIdentifier
     *
     * @return string|null
     */
    private function getPaymentMethodIdByHandler(string $handlerIdentifier): ?string
    {
        if (!array_key_exists($handlerIdentifier, $this->paymentMethodIds)) {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier))
                ->setLimit(1);

            $this->paymentMethodIds[$handlerIdentifier] = $this->paymentMethodRepository
                ->searchIds($criteria, Context::createDefaultContext())
                ->firstId();
        }

        return $this->paymentMethodIds[$handlerIdentifier];
    }

    /**
     * @param string $paymentMethodId
     *
     * @return string|null
     */
    private function getHandlerIdentifierByPaymentMethodId(string $paymentMethodId): ?string
    {
        if (!array_key_exists($paymentMethodId, $this->handlerIdentifiers)) {
            $paymentMethod = $this->paymentMethodRepository
                ->search(new Criteria([$paymentMethodId]), Context::createDefaultContext())
                ->first();

            $this->handlerIdentifiers[$paymentMethodId] = $paymentMethod instanceof PaymentMethodEntity
                ? $paymentMethod->getHandlerIdentifier()
                : null;
        }

        return $this->handlerIdentifiers[$paymentMethodId];
    }
}
