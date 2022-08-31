<?php
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

namespace Adyen\Shopware\PaymentMethods;

class PaymentMethods
{
    const PAYMENT_METHODS = [
        CardsPaymentMethod::class,
        IdealPaymentMethod::class,
        KlarnaAccountPaymentMethod::class,
        KlarnaPayNowPaymentMethod::class,
        KlarnaPayLaterPaymentMethod::class,
        RatepayPaymentMethod::class,
        RatepayDirectdebitPaymentMethod::class,
        SepaPaymentMethod::class,
        SofortPaymentMethod::class,
        PaypalPaymentMethod::class,
        OneClickPaymentMethod::class,
        GiroPayPaymentMethod::class,
        ApplePayPaymentMethod::class,
        GooglePayPaymentMethod::class,
        DotpayPaymentMethod::class,
        BancontactCardPaymentMethod::class,
        BancontactMobilePaymentMethod::class,
        AmazonPayPaymentMethod::class,
        TwintPaymentMethod::class,
        EpsPaymentMethod::class,
        SwishPaymentMethod::class,
        AlipayPaymentMethod::class,
        AlipayHkPaymentMethod::class,
        BlikPaymentMethod::class,
        ClearpayPaymentMethod::class,
        Facilypay3xPaymentMethod::class,
        Facilypay4xPaymentMethod::class,
        Facilypay6xPaymentMethod::class,
        Facilypay10xPaymentMethod::class,
        Facilypay12xPaymentMethod::class,
        AfterpayDefaultPaymentMethod::class,
        TrustlyPaymentMethod::class,
        PaysafecardPaymentMethod::class,
        GivexGiftCardPaymentMethod::class,
        WebshopGiftCardPaymentMethod::class,
        KadowereldGiftCardPaymentMethod::class,
        TCSTestGiftCardPaymentMethod::class,
        AlbelliGiftCardPaymentMethod::class,
        BijenkorfGiftCardPaymentMethod::class,
        VVVGiftCardPaymentMethod::class,
        GenericGiftCardPaymentMethod::class,
        GallGallGiftCardPaymentMethod::class,
        HunkemollerLingerieGiftCardPaymentMethod::class,
        BeautyGiftCardPaymentMethod::class,
        SVSGiftCardPaymentMethod::class,
        FashionChequeGiftCardPaymentMethod::class,
        DeCadeaukaartGiftCardPaymentMethod::class
    ];

    public static function getPaymentMethodHandlerByCode($paymentCode): ?string
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            /** @var PaymentMethodInterface $paymentMethod */
            $handlerIdentifier = (new $paymentMethod)->getPaymentHandler();
            if ($paymentCode === $handlerIdentifier::getPaymentMethodCode()) {
                return $handlerIdentifier;
            }
        }

        return null;
    }
}
