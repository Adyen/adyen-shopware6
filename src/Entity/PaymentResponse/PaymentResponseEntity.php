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

use DateTimeInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentResponseEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected string $orderTransactionId;

    /**
     * @var OrderTransactionEntity
     */
    protected OrderTransactionEntity $orderTransaction;

    /**
     * @var string
     */
    protected string $resultCode;

    /**
     * @var string
     */
    protected string $response;

    /**
     * @var DateTimeInterface|null
     */
    protected ?DateTimeInterface $createdAt;

    /**
     * @var string
     */
    protected string $pspreference;

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

    /**
     * @return string|null
     */
    public function getPspreference(): ?string
    {
        return $this->pspreference;
    }

    /**
     * @param string|null $pspreference
     */
    public function setPspreference(?string $pspreference): void
    {
        $this->pspreference = $pspreference;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
