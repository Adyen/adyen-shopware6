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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Entity\Notification;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class NotificationEntity extends Entity
{
    use EntityIdTrait;

    public const NOTIFICATION_STATUS_PENDING = 'PENDING';
    public const NOTIFICATION_STATUS_PROCESSED = 'PROCESSED';

    /**
     * @var string
     */
    protected $pspreference;

    /**
     * @var string
     */
    protected $originalReference;

    /**
     * @var string
     */
    protected $merchantReference;

    /**
     * @var string
     */
    protected $eventCode;

    /**
     * @var bool
     */
    protected $success;

    /**
     * @var string
     */
    protected $paymentMethod;

    /**
     * @var string
     */
    protected $amountValue;

    /**
     * @var string
     */
    protected $amountCurrency;

    /**
     * @var string
     */
    protected $reason;

    /**
     * @var bool
     */
    protected $live;

    /**
     * @var string
     */
    protected $additionalData;

    /**
     * @var bool
     */
    protected $done;

    /**
     * @var bool
     */
    protected $processing;

    /**
     * @var \DateTimeInterface|null
     */
    protected $scheduledProcessingTime;

    /**
     * @var int|null
     */
    protected $errorCount;

    /**
     * @var string
     */
    protected $errorMessage;

    /**
     * @return string
     */
    public function getPspreference(): string
    {
        return $this->pspreference;
    }

    /**
     * @param string $pspreference
     */
    public function setPspreference(string $pspreference): void
    {
        $this->pspreference = $pspreference;
    }

    /**
     * @return string
     */
    public function getOriginalReference(): string
    {
        return $this->originalReference;
    }

    /**
     * @param string $originalReference
     */
    public function setOriginalReference(string $originalReference): void
    {
        $this->originalReference = $originalReference;
    }

    /**
     * @return string
     */
    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    /**
     * @param string $merchantReference
     */
    public function setMerchantReference(string $merchantReference): void
    {
        $this->merchantReference = $merchantReference;
    }

    /**
     * @return string
     */
    public function getEventCode(): string
    {
        return $this->eventCode;
    }

    /**
     * @param string $eventCode
     */
    public function setEventCode(string $eventCode): void
    {
        $this->eventCode = $eventCode;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @param bool $success
     */
    public function setSuccess(bool $success): void
    {
        $this->success = $success;
    }

    /**
     * @return string
     */
    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    /**
     * @param string $paymentMethod
     */
    public function setPaymentMethod(string $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * @return string
     */
    public function getAmountValue(): string
    {
        return $this->amountValue;
    }

    /**
     * @param string $amountValue
     */
    public function setAmountValue(string $amountValue): void
    {
        $this->amountValue = $amountValue;
    }

    /**
     * @return string
     */
    public function getAmountCurrency(): string
    {
        return $this->amountCurrency;
    }

    /**
     * @param string $amountCurrency
     */
    public function setAmountCurrency(string $amountCurrency): void
    {
        $this->amountCurrency = $amountCurrency;
    }

    /**
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @param string $reason
     */
    public function setReason(string $reason): void
    {
        $this->reason = $reason;
    }

    /**
     * @return bool
     */
    public function isLive(): bool
    {
        return $this->live;
    }

    /**
     * @param bool $live
     */
    public function setLive(bool $live): void
    {
        $this->live = $live;
    }

    /**
     * @return string
     */
    public function getAdditionalData(): string
    {
        return $this->additionalData;
    }

    /**
     * @param string $additionalData
     */
    public function setAdditionalData(string $additionalData): void
    {
        $this->additionalData = $additionalData;
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * @param bool $done
     */
    public function setDone(bool $done): void
    {
        $this->done = $done;
    }

    /**
     * @return bool
     */
    public function getProcessing(): bool
    {
        return $this->processing;
    }

    /**
     * @param bool $processing
     */
    public function setProcessing(bool $processing): void
    {
        $this->processing = $processing;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getScheduledProcessingTime(): ?\DateTimeInterface
    {
        return $this->scheduledProcessingTime;
    }

    /**
     * @param \DateTimeInterface $scheduleProcessingTime
     */
    public function setScheduledProcessingTime(\DateTimeInterface $scheduleProcessingTime): void
    {
        $this->scheduledProcessingTime = $scheduleProcessingTime;
    }

    /**
     * @return int|null
     */
    public function getErrorCount(): ?int
    {
        return $this->errorCount;
    }

    /**
     * @param string $errorCount
     */
    public function setErrorCount(string $errorCount): void
    {
        $this->errorCount = $errorCount;
    }

    /**
     * @return string
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @param string $errorMessage
     */
    public function setErrorMessage(string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }
}
