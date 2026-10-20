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

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PaypalPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    public static bool $supportsManualCapture = true;
    public static bool $supportsPartialCapture = true;

    /**
     * Request attribute set when the PayPal payment has already been made from the cart and the order is created
     * afterwards, so pay() must not make another /payments call.
     */
    public const PAYMENT_FINALIZED_ATTRIBUTE = 'adyenPaypalPaymentFinalized';

    /**
     * Pays an existing order (e.g. on the order edit page) like any other payment method. When the payment
     * has already been made from the cart, it only redirects to the return URL.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $currentRequest = $this->requestStack->getCurrentRequest();
        if ($currentRequest && $currentRequest->attributes->getBoolean(self::PAYMENT_FINALIZED_ATTRIBUTE)) {
            return new RedirectResponse($transaction->getReturnUrl());
        }

        return parent::pay($transaction, $dataBag, $salesChannelContext);
    }

    /**
     * @return string
     */
    public static function getPaymentMethodCode(): string
    {
        return 'paypal';
    }
}
