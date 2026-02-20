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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Handlers;

use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class PaypalPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    public static bool $supportsManualCapture = true;
    public static bool $supportsPartialCapture = true;

    /**
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param Struct|null $validateStruct
     *
     * @return RedirectResponse
     *
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): RedirectResponse {
        return new RedirectResponse($transaction->getReturnUrl());
    }

    /**
     * @return string
     */
    public static function getPaymentMethodCode(): string
    {
        return 'paypal';
    }
}
