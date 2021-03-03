/*
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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

export default {
    updatablePaymentMethods: ['scheme', 'ideal', 'sepadirectdebit', 'oneclick', 'dotpay', 'bcmc'],
    componentsWithPayButton: ['applepay', 'paywithgoogle', 'paypal'],
    paymentMethodTypeHandlers: {
        'scheme': 'handler_adyen_cardspaymentmethodhandler',
        'ideal': 'handler_adyen_idealpaymentmethodhandler',
        'klarna': 'handler_adyen_klarnapaylaterpaymentmethodhandler',
        'klarna_account': 'handler_adyen_klarnaaccountpaymentmethodhandler',
        'klarna_paynow': 'handler_adyen_klarnapaynowpaymentmethodhandler',
        'sepadirectdebit': 'handler_adyen_sepapaymentmethodhandler',
        'sofort': 'handler_adyen_sofortpaymentmethodhandler',
        'paypal': 'handler_adyen_paypalpaymentmethodhandler',
        'oneclick': 'handler_adyen_oneclickpaymentmethodhandler',
        'giropay': 'handler_adyen_giropaypaymentmethodhandler',
        'applepay': 'handler_adyen_applepaypaymentmethodhandler',
        'paywithgoogle': 'handler_adyen_googlepaypaymentmethodhandler',
        'dotpay': 'handler_adyen_dotpaypaymentmethodhandler',
        'bcmc': 'handler_adyen_bancontactcardpaymentmethodhandler'
    }
}
