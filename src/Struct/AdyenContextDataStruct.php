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

class AdyenContextDataStruct extends Struct
{
    /**
     * @var string|null
     */
    protected ?string $clientKey = null;

    /**
     * @var string|null
     */
    protected ?string $environment = null;

    /**
     * @var bool
     */
    protected bool $hasPaymentStateData = false;

    /**
     * @return string|null
     */
    public function getClientKey(): ?string
    {
        return $this->clientKey;
    }

    /**
     * @param string|null $clientKey
     *
     * @return void
     */
    public function setClientKey(?string $clientKey): void
    {
        $this->clientKey = $clientKey;
    }

    /**
     * @return string|null
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * @param string|null $environment
     *
     * @return void
     */
    public function setEnvironment(?string $environment): void
    {
        $this->environment = $environment;
    }

    /**
     * @param bool $hasPaymentStateData
     *
     * @return void
     */
    public function setHasPaymentStateData(bool $hasPaymentStateData): void
    {
        $this->hasPaymentStateData = $hasPaymentStateData;
    }
}
