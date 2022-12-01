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

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class AdyenPaymentEntity extends Entity
{
    use EntityIdTrait;

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
    protected $merchantOrderReference;

    /**
     * @var string
     */
    protected $orderTransactionId;

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
    protected $additionalData;

    /**
     * @var string
     */
    protected $captureMode;

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
     * @return string
     */
    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    /**
     * @param int $orderTransactionId
     */
    public function setEventCode(int $orderTransactionId): void
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
}