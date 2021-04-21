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
                        alert(error.statusMessage);
                    } else {
                        alert(error.statusCode);
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
                returnUrl: function () {
                    const ref = new URL(location.href);
                    ref.searchParams.set('pending', '1');
                    return ref.toString();
                }()
            },
            prePay: true,
            sessionKey: 'amazonCheckoutSessionId',
            onClick: function (source, event, self) {
                return self.confirmOrderForm.checkValidity();
            },
            onError: (error) => {
                console.log(error);
                if (error.resultCode) {
                } else {
                    // Fatal error
                }
            },
            responseHandler: function (response) {
                if (response.action) {
                    // Handle additional action (3DS / redirect / other)
                    component.handleAction(response.action);
                } else {
                    // The merchant's function to show the final result or redirect to a final status page
                    //handleFinalResult(response);
                }
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
    }
}
