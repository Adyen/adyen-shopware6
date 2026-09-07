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

use Adyen\Shopware\Handlers\RivertyInstallmentsPaymentMethodHandler;

class RivertyInstallmentsPaymentMethod implements PaymentMethodInterface
{
    const RIVERTY_INSTALLMENTS_PAYMENT_METHOD_TYPE = 'riverty_installments';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'Riverty Installments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Riverty fixed installments of 3, 6, 12 or 24 months';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return RivertyInstallmentsPaymentMethodHandler::class;
    }

    /**
     * @inheritDoc
     */
    public function getGatewayCode(): string
    {
        return 'ADYEN_RIVERTY_INSTALLMENTS';
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
        return 'riverty_installments.png';
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'redirect';
    }
}
