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

use Adyen\Shopware\Handlers\SepadirectdebitRivertyPaymentMethodHandler;

class SepadirectdebitRivertyPaymentMethod implements PaymentMethodInterface
{
    const SEPADIRECTDEBIT_RIVERTY_PAYMENT_METHOD_TYPE = 'sepadirectdebit_riverty';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'Riverty Direct Debit';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Riverty secure direct debit';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return SepadirectdebitRivertyPaymentMethodHandler::class;
    }

    /**
     * @inheritDoc
     */
    public function getGatewayCode(): string
    {
        return 'ADYEN_SEPADIRECTDEBIT_RIVERTY';
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
        return 'sepadirectdebit_riverty.png';
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'redirect';
    }
}
