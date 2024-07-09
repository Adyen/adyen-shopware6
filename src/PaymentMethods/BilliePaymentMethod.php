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

use Adyen\Shopware\Handlers\BilliePaymentMethodHandler;

class BilliePaymentMethod implements PaymentMethodInterface
{

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'Billie';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Buy Now Pay Later with Billie';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return BilliePaymentMethodHandler::class;
    }

    /**
     * @inheritDoc
     */
    public function getGatewayCode(): string
    {
        return 'ADYEN_KLARNA_B2B';
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
        return 'billie.png';
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'redirect';
    }
}