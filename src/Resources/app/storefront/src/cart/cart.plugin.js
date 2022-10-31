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
        this.selectedGiftcard = null;
        this.initializeCheckoutComponent().then(function () {
            this.observeGiftcardSelection();
        }.bind(this));
        this.giftcardHeader = $('.adyen-giftcard-header');
        this.giftcardComponentClose = $('.adyen-close-giftcard-component');
        this.removeGiftcardButton = $('.adyen-remove-giftcard');
        this.minorUnitsQuotient = adyenGiftcardsConfiguration.totalInMinorUnits/adyenGiftcardsConfiguration.totalPrice;
        this.giftcardDiscount = (adyenGiftcardsConfiguration.giftcardDiscount / this.minorUnitsQuotient).toFixed(2);
        this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);
        this.offCanvasCartSummaryBlock = $('.offcanvas-summary-list');
        this.shoppingCartSummaryBlock = $('.checkout-aside-summary-list');
        this.offCanvasSummaryDetails = null;
        this.shoppingCartSummaryDetails = null;

        this.giftcardComponentClose.on('click', function (event) {
            $(event.currentTarget).hide();
            this.selectedGiftcard = null;
            this.giftcardHeader.html(' ');
            if (this.paymentMethodInstance) {
                this.paymentMethodInstance.unmount();
            }
        }.bind(this));
        this.removeGiftcardButton.on('click', function (event) {
            this.removeGiftcard();
        }.bind(this));

        if (parseInt(adyenGiftcardsConfiguration.giftcardDiscount)) {
            this.onGiftcardSelected();
        }
    }

    async initializeCheckoutComponent() {
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            amount: {
                currency: adyenGiftcardsConfiguration.currency,
                value: adyenGiftcardsConfiguration.totalInMinorUnits,
            }
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    observeGiftcardSelection() {
        $('.adyen-giftcard').on('click', function (event) {
            this.selectedGiftcard = JSON.parse(event.currentTarget.dataset.giftcard);
            this.mountGiftcardComponent(this.selectedGiftcard.extensions.adyenGiftcardData[0]);
        }.bind(this));
    }

    mountGiftcardComponent(giftcard) {
        if (this.giftcardDiscount > 0) {
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
                    if (balance >= adyenGiftcardsConfiguration.totalInMinorUnits) {
                        // Render pay button for giftcard
                        resolve(response);
                    } else {
                        this.remainingAmount = ((adyenGiftcardsConfiguration.totalInMinorUnits - balance) / this.minorUnitsQuotient).toFixed(2);
                        resolve(response);
                        this.saveGiftcardStateData(data, balance.toString());
                    }
                }
            }.bind(this)
        );
    }

    onGiftcardSubmit(state) {
        ElementLoadingIndicatorUtil.create(document.body);
        if (state.isValid) {
            this.setGiftcardAsPaymentMethod(state.data);
        }
    }

    saveGiftcardStateData(stateData, amountInMinorUnits) {
        // save state data to database, set giftcard as payment method and proceed to checkout
        this._client.post(adyenGiftcardsConfiguration.setGiftcardUrl, JSON.stringify({ stateData, amount: amountInMinorUnits }), function (response) {
            if (JSON.parse(response).length === 0) {
                this.giftcardDiscount = (amountInMinorUnits / this.minorUnitsQuotient).toFixed(2);
                this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);
                this.onGiftcardSelected();
                ElementLoadingIndicatorUtil.remove(document.body);
            }
        }.bind(this));
    }

    setGiftcardAsPaymentMethod(stateData) {
        this._client.patch(adyenGiftcardsConfiguration.switchContextUrl, JSON.stringify({paymentMethodId: this.selectedGiftcard.id}), function (response) {
            this.saveGiftcardStateData(stateData, adyenGiftcardsConfiguration.totalInMinorUnits)
        }.bind(this));
    }

    removeGiftcard() {
        ElementLoadingIndicatorUtil.create(document.body);
        this._client.post(adyenGiftcardsConfiguration.removeGiftcardUrl, new FormData, function(response) {
            if (JSON.parse(response).length === 0) {
                this.giftcardDiscount = 0;
                this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);
                if (this.offCanvasCartSummaryBlock.length) {
                    this.offCanvasCartSummaryBlock.children('.adyen-giftcard-summary').remove();
                }
                if (this.shoppingCartSummaryBlock.length) {
                    this.shoppingCartSummaryBlock.children('.adyen-giftcard-summary').remove();
                }
                this.removeGiftcardButton.hide();
                // Show giftcards
                $('.adyen-giftcard').show();
                ElementLoadingIndicatorUtil.remove(document.body);
            }
        }.bind(this));
    }

    appendGiftcardSummary() {
        if (this.offCanvasCartSummaryBlock.length) {
            this.offCanvasSummaryDetails = $('<dt class="col-7 summary-label summary-total adyen-giftcard-summary">Giftcard discount</dt>' +
                '<dd class="col-5 summary-value summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.giftcardDiscount + '</dd>' +
                '<dt class="col-7 summary-label summary-total adyen-giftcard-summary">Remaining amount</dt>' +
                '<dd class="col-5 summary-value summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.remainingAmount + '</dd>');
            this.offCanvasSummaryDetails.appendTo(this.offCanvasCartSummaryBlock[0]);
        }
        if(this.shoppingCartSummaryBlock.length) {
            this.shoppingCartSummaryDetails = $('<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">Giftcard discount</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.giftcardDiscount + '</dd>' +
                '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">Remaining amount</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.remainingAmount + '</dd>');
            this.shoppingCartSummaryDetails.appendTo(this.shoppingCartSummaryBlock[0]);
        }
    }

    onGiftcardSelected() {
        // Remove component
        if (this.paymentMethodInstance) {
            this.paymentMethodInstance.unmount();
        }
        this.giftcardComponentClose.hide();
        // Hide giftcards
        $('.adyen-giftcard').hide();
        this.giftcardHeader.html(' ');

        this.appendGiftcardSummary();
        // Show Remove button
        this.removeGiftcardButton.show();
    }
}
