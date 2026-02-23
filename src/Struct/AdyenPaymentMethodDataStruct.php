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

class AdyenPaymentMethodDataStruct extends Struct
{
    /**
     * @var string|null
     */
    protected ?string $type = null;

    /**
     * @var array|null
     */
    protected ?array $paymentMethodConfig = null;

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string|null $type
     *
     * @return void
     */
    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    /**
     * @return array|null
     */
    public function getPaymentMethodConfig(): ?array
    {
        return $this->paymentMethodConfig;
    }

    /**
     * @param array|null $paymentMethodConfig
     *
     * @return void
     */
    public function setPaymentMethodConfig(?array $paymentMethodConfig): void
    {
        $this->paymentMethodConfig = $paymentMethodConfig;
    }
}
