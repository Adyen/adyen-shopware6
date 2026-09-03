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

namespace Adyen\Shopware\PaymentMethods;

use Adyen\Shopware\Handlers\RivertyAccountPaymentMethodHandler;

class RivertyAccountPaymentMethod implements PaymentMethodInterface
{
    const RIVERTY_ACCOUNT_PAYMENT_METHOD_TYPE = 'riverty_account';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'Riverty Monthly Invoice';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Riverty monthly consolidated invoice';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return RivertyAccountPaymentMethodHandler::class;
    }

    /**
     * @inheritDoc
     */
    public function getGatewayCode(): string
    {
        return 'ADYEN_RIVERTY_ACCOUNT';
    }

    /**
     * @inheritDoc
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getLogo(): string
    {
        return 'riverty_account.png';
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'redirect';
    }
}
