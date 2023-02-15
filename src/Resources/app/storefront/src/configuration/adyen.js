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
 * Adyen Payment Module
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

export default {
    updatablePaymentMethods: [
        'scheme', 'ideal', 'sepadirectdebit', 'oneclick', 'dotpay', 'bcmc', 'bcmc_mobile', 'blik', 'eps', 'facilypay_3x',
        'facilypay_4x', 'facilypay_6x', 'facilypay_10x', 'facilypay_12x', 'afterpay_default', 'ratepay',
        'ratepay_directdebit', 'giftcard', 'paybright', 'affirm', 'multibanco', 'mbway', 'vipps', 'mobilepay',
        'wechatpayQR', 'wechatpayWeb', 'paybybank'
    ],
    componentsWithPayButton: {
        'applepay': {
            extra: {},
            onClick(resolve, reject, self) {
                if (!self.confirmOrderForm.checkValidity()) {
                    reject();
                    return false;
                } else {
                    resolve();
                    return true;
                }
            }
        },
        'googlepay': {
            extra: {
                buttonSizeMode: 'fill',
            },
            onClick: function (resolve, reject, self) {
                if (!self.confirmOrderForm.checkValidity()) {
                    reject();
                    return false;
                } else {
                    resolve();
                    return true;
                }
            },
            onError: function(error, component, self) {
                if (error.statusCode !== 'CANCELED') {
                    if ('statusMessage' in error) {
                        console.log(error.statusMessage);
                    } else {
                        console.log(error.statusCode);
                    }
                }
            }
        },
        'paypal': {
            extra: {},
            onClick: function (source, event, self) {
                return self.confirmOrderForm.checkValidity();
            },
            onError: function(error, component, self) {
                component.setStatus('ready');
                window.location.href = self.errorUrl.toString();
            },
            onCancel: function (data, component, self) {
                component.setStatus('ready');
                window.location.href = self.errorUrl.toString();
            },
            responseHandler: function (plugin, response) {
                try {
                    response = JSON.parse(response);
                    if (response.isFinal) {
                        location.href = plugin.returnUrl;
                    }
                    // Load Paypal popup window with component.handleAction
                    this.handleAction(response.action);
                } catch (e) {
                    console.error(e);
                }
            }
        },
        'amazonpay': {
            extra: {
                productType: 'PayAndShip',
                checkoutMode: 'ProcessOrder',
                returnUrl: location.href
            },
            prePayRedirect: true,
            sessionKey: 'amazonCheckoutSessionId',
            onClick: function (resolve, reject, self) {
                if (!self.confirmOrderForm.checkValidity()) {
                    reject();
                    return false;
                } else {
                    resolve();
                    return true;
                }
            },
            onError: (error, component) => {
                console.log(error);
                component.setStatus('ready');
            }
        },
    },
    paymentMethodTypeHandlers: {
        'scheme': 'handler_adyen_cardspaymentmethodhandler',
        'ideal': 'handler_adyen_idealpaymentmethodhandler',
        'klarna': 'handler_adyen_klarnapaylaterpaymentmethodhandler',
        'klarna_account': 'handler_adyen_klarnaaccountpaymentmethodhandler',
        'klarna_paynow': 'handler_adyen_klarnapaynowpaymentmethodhandler',
        'ratepay': 'handler_adyen_ratepaypaymentmethodhandler',
        'ratepay_directdebit': 'handler_adyen_ratepaydirectdebitpaymentmethodhandler',
        'sepadirectdebit': 'handler_adyen_sepapaymentmethodhandler',
        'sofort': 'handler_adyen_sofortpaymentmethodhandler',
        'paypal': 'handler_adyen_paypalpaymentmethodhandler',
        'oneclick': 'handler_adyen_oneclickpaymentmethodhandler',
        'giropay': 'handler_adyen_giropaypaymentmethodhandler',
        'applepay': 'handler_adyen_applepaypaymentmethodhandler',
        'googlepay': 'handler_adyen_googlepaypaymentmethodhandler',
        'dotpay': 'handler_adyen_dotpaypaymentmethodhandler',
        'bcmc': 'handler_adyen_bancontactcardpaymentmethodhandler',
        'bcmc_mobile': 'handler_adyen_bancontactmobilepaymentmethodhandler',
        'amazonpay': 'handler_adyen_amazonpaypaymentmethodhandler',
        'twint': 'handler_adyen_twintpaymentmethodhandler',
        'eps': 'handler_adyen_epspaymentmethodhandler',
        'swish': 'handler_adyen_swishpaymentmethodhandler',
        'alipay': 'handler_adyen_alipaypaymentmethodhandler',
        'alipay_hk': 'handler_adyen_alipayhkpaymentmethodhandler',
        'blik': 'handler_adyen_blikpaymentmethodhandler',
        'clearpay': 'handler_adyen_clearpaypaymentmethodhandler',
        'facilypay_3x': 'handler_adyen_facilypay3xpaymentmethodhandler',
        'facilypay_4x': 'handler_adyen_facilypay4xpaymentmethodhandler',
        'facilypay_6x': 'handler_adyen_facilypay6xpaymentmethodhandler',
        'facilypay_10x': 'handler_adyen_facilypay10xpaymentmethodhandler',
        'facilypay_12x': 'handler_adyen_facilypay12xpaymentmethodhandler',
        'afterpay_default': 'handler_adyen_afterpaydefaultpaymentmethodhandler',
        'trustly': 'handler_adyen_trustlypaymentmethodhandler',
        'paysafecard': 'handler_adyen_paysafecardpaymentmethodhandler',
        'givex': 'handler_adyen_givexgiftcardpaymentmethodhandler',
        'webshopgiftcard': 'handler_adyen_webshopgiftcardpaymentmethodhandler',
        'kadowereld': 'handler_adyen_kadowereldgiftcardpaymentmethodhandler',
        'tcstestgiftcard': 'handler_adyen_tcstestgiftcardpaymentmethodhandler',
        'albelligiftcard': 'handler_adyen_albelligiftcardpaymentmethodhandler',
        'bijcadeaucard': 'handler_adyen_bijenkorfgiftcardpaymentmethodhandler',
        'vvvgiftcard': 'handler_adyen_vvvgiftcardpaymentmethodhandler',
        'genericgiftcard': 'handler_adyen_genericgiftcardpaymentmethodhandler',
        'gallgall': 'handler_adyen_gallgallgiftcardpaymentmethodhandler',
        'hmlingerie': 'handler_adyen_hunkemollerlingeriegiftcardpaymentmethodhandler',
        'beautycadeaukaart': 'handler_adyen_beautygiftcardpaymentmethodhandler',
        'svs': 'handler_adyen_svsgiftcardpaymentmethodhandler',
        'fashioncheque': 'handler_adyen_fashionchequegiftcardpaymentmethodhandler',
        'decadeaukaart': 'handler_adyen_decadeaukaartgiftcardpaymentmethodhandler',
        'mbway': 'handler_adyen_mbwaypaymentmethodhandler',
        'multibanco': 'handler_adyen_multibancopaymentmethodhandler',
        'wechatpayQR': 'handler_adyen_wechatpayqrpaymentmethodhandler',
        'wechatpayWeb': 'handler_adyen_wechatpaywebpaymentmethodhandler',
        'mobilepay': 'handler_adyen_mobilepaypaymentmethodhandler',
        'vipps': 'handler_adyen_vippspaymentmethodhandler',
        'affirm': 'handler_adyen_affirmpaymentmethodhandler',
        'paybright': 'handler_adyen_paybrightpaymentmethodhandler',
        'paybybank': 'handler_adyen_openbankingpaymentmethodhandler'
    }
}
