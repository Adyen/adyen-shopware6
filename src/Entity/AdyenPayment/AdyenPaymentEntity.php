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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Entity\AdyenPayment;

use DateTimeInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class AdyenPaymentEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected string $pspreference;

    /**
     * @var string
     */
    protected string $originalReference;

    /**
     * @var string
     */
    protected string $merchantReference;

    /**
     * @var string
     */
    protected string $merchantOrderReference;

    /**
     * @var int
     */
    protected int $orderTransactionId;

    /**
     * @var string
     */
    protected string $paymentMethod;

    /**
     * @var int
     */
    protected int $amountValue;

    /**
     * @var string
     */
    protected string $amountCurrency;

    /**
     * @var int
     */
    protected int $totalRefunded;

    /**
     * @var string
     */
    protected string $additionalData;

    /**
     * @var string
     */
    protected string $captureMode;

    /**
     * @var DateTimeInterface|null
     */
    protected $createdAt;

    /**
     * @var DateTimeInterface|null
     */
    protected $updatedAt;

    /**
     * @var OrderTransactionEntity
     */
    protected OrderTransactionEntity $orderTransaction;

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
    public function getMerchantOrderReference(): string
    {
        return $this->merchantOrderReference;
    }

    /**
     * @param string $merchantOrderReference
     */
    public function setMerchantOrderReference(string $merchantOrderReference): void
    {
        $this->merchantOrderReference = $merchantOrderReference;
    }

    /**
     * @return int
     */
    public function getOrderTransactionId(): int
    {
        return $this->orderTransactionId;
    }

    /**
     * @param int $orderTransactionId
     */
    public function setOrderTransactionId(int $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
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
     * @return int
     */
    public function getAmountValue(): int
    {
        return $this->amountValue;
    }

    /**
     * @param int $amountValue
     */
    public function setAmountValue(int $amountValue): void
    {
        $this->amountValue = $amountValue;
    }

    /**
     * @return int
     */
    public function getTotalRefunded(): int
    {
        return $this->totalRefunded;
    }

    /**
     * @param int $totalRefunded
     */
    public function setTotalRefunded(int $totalRefunded): void
    {
        $this->totalRefunded = $totalRefunded;
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
     * @return string
     */
    public function getCaptureMode(): string
    {
        return $this->captureMode;
    }

    /**
     * @param string $captureMode
     */
    public function setCaptureMode(string $captureMode): void
    {
        $this->captureMode = $captureMode;
    }

    /**
     * @return DateTimeInterface|null
     */
    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @return OrderTransactionEntity
     */
    public function getOrderTransaction(): OrderTransactionEntity
    {
        return $this->orderTransaction;
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     */
    public function setOrderTransaction(OrderTransactionEntity $orderTransaction): void
    {
        $this->orderTransaction = $orderTransaction;
    }
}
