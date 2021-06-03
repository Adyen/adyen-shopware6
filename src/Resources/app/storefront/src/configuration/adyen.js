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
    updatablePaymentMethods: ['scheme', 'ideal', 'sepadirectdebit', 'oneclick', 'dotpay', 'bcmc', 'blik', 'eps', 'facilypay_3x', 'facilypay_4x', 'facilypay_6x', 'facilypay_10x', 'facilypay_12x'],
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
        'paywithgoogle': {
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
                productType: 'PayOnly',
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
        'sepadirectdebit': 'handler_adyen_sepapaymentmethodhandler',
        'sofort': 'handler_adyen_sofortpaymentmethodhandler',
        'paypal': 'handler_adyen_paypalpaymentmethodhandler',
        'oneclick': 'handler_adyen_oneclickpaymentmethodhandler',
        'giropay': 'handler_adyen_giropaypaymentmethodhandler',
        'applepay': 'handler_adyen_applepaypaymentmethodhandler',
        'paywithgoogle': 'handler_adyen_googlepaypaymentmethodhandler',
        'dotpay': 'handler_adyen_dotpaypaymentmethodhandler',
        'bcmc': 'handler_adyen_bancontactcardpaymentmethodhandler',
        'amazonpay': 'handler_adyen_amazonpaypaymentmethodhandler',
        'twint': 'handler_adyen_twintpaymentmethodhandler',
        'eps': 'handler_adyen_epspaymentmethodhandler',
        'swish': 'handler_adyen_swishpaymentmethodhandler',
        'alipay': 'handler_adyen_alipaypaymentmethodhandler',
        'alipay_hk': 'handler_adyen_alipayhkpaymentmethodhandler',
        'blik': 'handler_adyen_blikpaymentmethodhandler',
        'facilypay_3x': 'handler_adyen_facilypay3xpaymentmethodhandler',
        'facilypay_4x': 'handler_adyen_facilypay4xpaymentmethodhandler',
        'facilypay_6x': 'handler_adyen_facilypay6xpaymentmethodhandler',
        'facilypay_10x': 'handler_adyen_facilypay10xpaymentmethodhandler',
        'facilypay_12x': 'handler_adyen_facilypay12xpaymentmethodhandler',
        'trustly': 'handler_adyen_trustlypaymentmethodhandler',
    }
}
