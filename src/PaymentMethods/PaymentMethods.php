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
        GiftCardPaymentMethod::class,
        PayBrightPaymentMethod::class,
        AffirmPaymentMethod::class,
        WechatpaywebPaymentMethod::class,
        WechatpayqrPaymentMethod::class,
        MultibancoPaymentMethod::class,
        MbwayPaymentMethod::class,
        VippsPaymentMethod::class,
        MobilePayPaymentMethod::class,
        OpenBankingPaymentMethod::class,
        BilliePaymentMethod::class
    ];
}
