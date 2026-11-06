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

namespace Adyen\Shopware\Models\Capture;

use InvalidArgumentException;

/**
 * Effective capture configuration of a payment: the capture mode and the parameters that belong to it.
 */
final class CaptureConfiguration
{
    /**
     * @var string $mode One of the CaptureMode values, never CaptureMode::GLOBAL.
     */
    private $mode;

    /**
     * @var int|null $delayDays Set for CaptureMode::DELAYED only.
     */
    private $delayDays;

    /**
     * @var string|null $orderStateId Delivery state id, set for CaptureMode::ON_SHIPMENT only.
     */
    private $orderStateId;

    /**
     * @var int|null $retryIntervalSeconds Set for CaptureMode::ON_SHIPMENT only.
     */
    private $retryIntervalSeconds;

    /**
     * Parameters that do not belong to the mode are discarded.
     *
     * @param string $mode
     * @param int|null $delayDays
     * @param string|null $orderStateId
     * @param int|null $retryIntervalSeconds
     */
    public function __construct(
        string $mode,
        ?int $delayDays = null,
        ?string $orderStateId = null,
        ?int $retryIntervalSeconds = null
    ) {
        if (CaptureMode::tryFromConfig($mode) === null || $mode === CaptureMode::GLOBAL) {
            throw new InvalidArgumentException('A capture configuration needs an effective capture mode.');
        }

        $this->mode = $mode;
        $this->delayDays = $mode === CaptureMode::DELAYED ? $delayDays : null;
        $this->orderStateId = $mode === CaptureMode::ON_SHIPMENT ? $orderStateId : null;
        $this->retryIntervalSeconds = $mode === CaptureMode::ON_SHIPMENT ? $retryIntervalSeconds : null;
    }

    /**
     * Configuration for payment methods without manual capture support.
     *
     * @return self
     */
    public static function automatic(): self
    {
        return new self(CaptureMode::IMMEDIATE);
    }

    /**
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * @return int|null
     */
    public function getDelayDays(): ?int
    {
        return $this->delayDays;
    }

    /**
     * @return string|null
     */
    public function getOrderStateId(): ?string
    {
        return $this->orderStateId;
    }

    /**
     * @return int|null
     */
    public function getRetryIntervalSeconds(): ?int
    {
        return $this->retryIntervalSeconds;
    }

    /**
     * @return bool True if the payment is only authorised and Adyen does not capture it by itself.
     */
    public function isManualCapture(): bool
    {
        return CaptureMode::isManualCapture($this->mode);
    }

    /**
     * @return bool True if the payment is not captured at authorisation, so the transaction stays authorised
     *              and the payment can be captured from the shop.
     */
    public function isDeferredCapture(): bool
    {
        return CaptureMode::isDeferredCapture($this->mode);
    }

    /**
     * @return bool
     */
    public function isCaptureOnShipment(): bool
    {
        return $this->mode === CaptureMode::ON_SHIPMENT;
    }

    /**
     * @return int|null 0 for immediate capture, the delay in hours for delayed capture, otherwise null.
     */
    public function getCaptureDelayHours(): ?int
    {
        if ($this->mode === CaptureMode::IMMEDIATE) {
            return 0;
        }

        if ($this->mode === CaptureMode::DELAYED) {
            return $this->delayDays === null ? null : $this->delayDays * 24;
        }

        return null;
    }

    /**
     * @return array{mode: string, delayDays: int|null, orderStateId: string|null, retryIntervalSeconds: int|null}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'delayDays' => $this->delayDays,
            'orderStateId' => $this->orderStateId,
            'retryIntervalSeconds' => $this->retryIntervalSeconds,
        ];
    }

    /**
     * @param mixed $data Snapshot as stored by toArray().
     *
     * @return self|null Null if the data is not a valid snapshot.
     */
    public static function fromArray($data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $mode = CaptureMode::tryFromConfig($data['mode'] ?? null);
        if ($mode === null || $mode === CaptureMode::GLOBAL) {
            return null;
        }

        $delayDays = $data['delayDays'] ?? null;
        $orderStateId = $data['orderStateId'] ?? null;
        $retryIntervalSeconds = $data['retryIntervalSeconds'] ?? null;

        return new self(
            $mode,
            is_int($delayDays) ? $delayDays : null,
            is_string($orderStateId) && $orderStateId !== '' ? $orderStateId : null,
            is_int($retryIntervalSeconds) ? $retryIntervalSeconds : null
        );
    }
}
