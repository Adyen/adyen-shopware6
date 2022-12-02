<?php
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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Entity\PaymentResponse;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentResponseEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $orderTransactionId;

    /**
     * @var OrderTransactionEntity
     */
    protected $orderTransaction;

    /**
     * @var string
     */
    protected $resultCode;

    /**
     * @var string
     */
    protected $refusalReason;

    /**
     * @var string
     */
    protected $refusalReasonCode;

    /**
     * @var string
     */
    protected $response;

    /**
     * @var \DateTimeInterface|null
     */
    protected $createdAt;

    /**
     * @return string
     */
    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    /**
     * @param string $orderTransactionId
     */
    public function setOrderTransactionId(string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }

    /**
     * @return OrderTransactionEntity|null
     */
    public function getOrderTransaction(): ?OrderTransactionEntity
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

    /**
     * @return string
     */
    public function getResultCode(): string
    {
        return $this->resultCode;
    }

     /**
     * @param string $resultCode
     */
    public function setResultCode(string $resultCode): void
    {
        $this->resultCode = $resultCode;
    }

    /**
     * @return string
     */
    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }

    /**
     * @param string $refusalReason
     */
    public function setRefusalReason(string $refusalReason): void
    {
        $this->refusalReason = $refusalReason;
    }

    /**
     * @return string
     */
    public function getRefusalReasonCode(): ?string
    {
        return $this->refusalReasonCode;
    }

    /**
     * @param string $refusalReasonCode
     */
    public function setRefusalReasonCode(string $refusalReasonCode): void
    {
        $this->refusalReasonCode = $refusalReasonCode;
    }

    /**
     * @return string
     */
    public function getResponse(): string
    {
        return $this->response;
    }

    /**
     * @param string $response
     */
    public function setResponse(string $response): void
    {
        $this->response = $response;
    }
}
