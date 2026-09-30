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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Entity\PaypalPaymentAttempt;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * A PayPal payment sent to Adyen before its Shopware order exists. Deleted once the order is created, so a
 * remaining record is what lets the webhook fallback reverse a payment whose order was never created.
 */
class PaypalPaymentAttemptEntity extends Entity
{
    use EntityIdTrait;

    const STATUS_OPEN = 'open';
    const STATUS_REVERSED = 'reversed';
    const STATUS_REVERSAL_FAILED = 'reversal_failed';

    /**
     * @var string
     */
    protected string $merchantReference;

    /**
     * @var string
     */
    protected string $salesChannelId;

    /**
     * @var string|null
     */
    protected ?string $pspReference = null;

    /**
     * @var string
     */
    protected string $status;

    /**
     * @var string|null
     */
    protected ?string $reversalPspReference = null;

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
    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    /**
     * @param string $salesChannelId
     */
    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    /**
     * @return string|null
     */
    public function getPspReference(): ?string
    {
        return $this->pspReference;
    }

    /**
     * @param string|null $pspReference
     */
    public function setPspReference(?string $pspReference): void
    {
        $this->pspReference = $pspReference;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return string|null
     */
    public function getReversalPspReference(): ?string
    {
        return $this->reversalPspReference;
    }

    /**
     * @param string|null $reversalPspReference
     */
    public function setReversalPspReference(?string $reversalPspReference): void
    {
        $this->reversalPspReference = $reversalPspReference;
    }

    /**
     * @return bool
     */
    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
