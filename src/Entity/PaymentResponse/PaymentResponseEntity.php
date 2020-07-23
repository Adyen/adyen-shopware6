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

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentResponseEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $orderNumber;

    /**
     * @var string
     */
    protected $salesChannelApiContextToken;

    /**
     * @var string
     */
    protected $resultCode;

    /**
     * @var string
     */
    protected $response;

    /**
     * @return string
     */
    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    /**
     * @param string $orderNumber
     */
    public function setOrderNumber(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    /**
     * @return string
     */
    public function getResultCode(): string
    {
        return $this->resultCode;
    }

    /**
     * @return string
     */
    public function getSalesChannelApiContextToken(): string
    {
        return $this->salesChannelApiContextToken;
    }

    /**
     * @param string $salesChannelApiContextToken
     */
    public function setSalesChannelApiContextToken(string $salesChannelApiContextToken): void
    {
        $this->salesChannelApiContextToken = $salesChannelApiContextToken;
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
}
