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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

export default class CartPlugin extends Plugin {
    init() {
        this._client = new StoreApiClient();
        this.adyenCheckout = Promise;
        this.paymentMethodInstance = null;
        this.initializeCheckoutComponent().then(function () {
            this.observeGiftcardSelection();
        }.bind(this));
        this.giftcards = adyenGiftcardsConfiguration.giftcards;
        this.giftcardHeader = $('.adyen-giftcard-header');
        this.giftcardComponentClose = $('.adyen-close-giftcard-component');
        this.giftcardComponentClose.on('click', function (event) {
            $(event.currentTarget).hide();
            this.giftcardHeader.html(' ');
            if (this.paymentMethodInstance) {
                this.paymentMethodInstance.unmount();
            }
        }.bind(this));
        this.giftCardAmountPaid = 0;
        this.remainingAmount = 0;
    }

    async initializeCheckoutComponent() {
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            amount: {
                currency: adyenGiftcardsConfiguration.currency,
                value: adyenGiftcardsConfiguration.totalPrice,
            }
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    observeGiftcardSelection() {
        $('.adyen-giftcard').on('click', function (event) {
            let giftcard = JSON.parse(event.currentTarget.dataset.giftcard)[0];
            this.mountGiftcardComponent(giftcard);
        }.bind(this));
    }

    mountGiftcardComponent(giftcard) {
        if (this.giftCardAmountPaid) {
            return;
        }
        if (this.paymentMethodInstance) {
            this.paymentMethodInstance.unmount();
        }
        ElementLoadingIndicatorUtil.create(DomAccess.querySelector(document, '#adyen-giftcard-component'));
        this.giftcardHeader.html(giftcard.name);
        this.giftcardComponentClose.show();
        const giftcardConfiguration = Object.assign({}, giftcard, {
            showPayButton: true,
            onBalanceCheck: this.handleBalanceCheck.bind(this),
            onSubmit: this.onGiftcardSubmit.bind(this)
        });
        try {
            this.paymentMethodInstance = this.adyenCheckout.create('giftcard', giftcardConfiguration);
            this.paymentMethodInstance.mount('#adyen-giftcard-component');

        } catch (e) {
            console.log('giftcard not available');
        }
        ElementLoadingIndicatorUtil.remove(DomAccess.querySelector(document, '#adyen-giftcard-component'));
    }

    handleBalanceCheck(resolve, reject, data) {
        this._client.post(
            `${adyenGiftcardsConfiguration.checkBalanceUrl}`,
            JSON.stringify({paymentMethod: data.paymentMethod}),
            function (response) {
                response = JSON.parse(response);
                if ('Success' !== response.resultCode) {
                    console.error(response.resultCode);
                    reject(response.resultCode);
                } else {
                    // 0. compare balance to total amount to be paid
                    const balance = parseFloat(response.balance.value);
                    if (balance >= adyenGiftcardsConfiguration.totalPrice) {
                        // Render pay button for giftcard
                        resolve(response);
                    } else {
                        // 1. create order
                        this.remainingAmount = adyenGiftcardsConfiguration.totalPrice - balance;

                        // 2. make payment request with orderData
                    }
                }
            }.bind(this)
        );
    }

    onGiftcardSubmit(state) {
        ElementLoadingIndicatorUtil.create(document.body);
        if (state.isValid) {
            // save state data to database, set giftcard as payment method and proceed to checkout
            this._client.post(adyenGiftcardsConfiguration.setGiftcardUrl, JSON.stringify({ giftcardStateData: state.data }), function (response) {
                if (JSON.parse(response).length === 0) {
                    // giftcard is applied
                    this.giftCardAmountPaid = adyenGiftcardsConfiguration.totalPrice;

                    const offCanvasCartSummaryBlock = $('.offcanvas-summary-list');
                    const shoppingCartSummaryBlock = $('.checkout-aside-summary-list');
                    let offCanvasSummaryDetails;
                    let shoppingCartSummaryDetails;
                    if (offCanvasCartSummaryBlock.length) {
                        offCanvasSummaryDetails = '<dt class="col-7 summary-label summary-total">Amount to be paid by giftcard</dt>' +
                            '<dd class="col-5 summary-value summary-total">' + this.giftCardAmountPaid + '</dd>' +
                            '<dt class="col-7 summary-label summary-total">Remaining amount</dt>' +
                            '<dd class="col-5 summary-total summary-total">' + this.remainingAmount + '</dd>';
                        $(offCanvasSummaryDetails).appendTo(offCanvasCartSummaryBlock[0]);
                    }
                    if(shoppingCartSummaryBlock.length) {
                        shoppingCartSummaryDetails = '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total">Amount to be paid by giftcard</dt>' +
                            '<dd class="col-5 checkout-aside-summary-total checkout-aside-summary-total">' + this.giftCardAmountPaid + '</dd>' +
                            '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total">Remaining amount</dt>' +
                            '<dd class="col-5 checkout-aside-summary-total checkout-aside-summary-total">' + this.remainingAmount + '</dd>';
                        $(shoppingCartSummaryDetails).appendTo(shoppingCartSummaryBlock[0]);
                    }
                    if (this.paymentMethodInstance) {
                        this.paymentMethodInstance.unmount();
                    }
                    $('<a href="#">Remove giftcard</a>').appendTo($('#adyen-giftcard-component'));
                    this.giftcardComponentClose.hide();
                    ElementLoadingIndicatorUtil.remove(document.body);
                }
            }.bind(this))
        }
    }

    createAdyenOrder () {

    }

    makePaymentWithGiftcard() {

    }

    cancelOrder() {

    }
}
