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

namespace Adyen\Shopware\Struct;

use Shopware\Core\Framework\Struct\Struct;

class ContextAdyenDataStruct extends Struct
{
    /**
     * @var string|null
     */
    protected $clientKey = null;

    /**
     * @var string|null
     */
    protected $environment = null;

    /**
     * @var bool
     */
    protected $hasPaymentStateData = false;

    public function getClientKey(): ?string
    {
        return $this->clientKey;
    }

    public function setClientKey(?string $clientKey): void
    {
        $this->clientKey = $clientKey;
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    public function setEnvironment(?string $environment): void
    {
        $this->environment = $environment;
    }

    public function isHasPaymentStateData(): bool
    {
        return $this->hasPaymentStateData;
    }

    public function setHasPaymentStateData(bool $hasPaymentStateData): void
    {
        $this->hasPaymentStateData = $hasPaymentStateData;
    }
}
