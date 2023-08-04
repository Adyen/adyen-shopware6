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
import HttpClient from 'src/service/http-client.service';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

export default class CartPlugin extends Plugin {
    init() {
        let self = this;

        this._client = new HttpClient();
        this.adyenCheckout = Promise;
        this.paymentMethodInstance = null;
        this.selectedGiftcard = null;
        this.initializeCheckoutComponent().then(function () {
            this.observeGiftcardSelection();
        }.bind(this));
        this.adyenGiftcard = DomAccess.querySelectorAll(document, '.adyen-giftcard');
        this.giftcardHeader = DomAccess.querySelector(document, '.adyen-giftcard-header');
        this.giftcardComponentClose = DomAccess.querySelector(document, '.adyen-close-giftcard-component');
        this.removeGiftcardButton =  DomAccess.querySelector(document, '.adyen-remove-giftcard');
        this.remainingBalanceField = DomAccess.querySelector(document, '.adyen-remaining-balance');
        this.minorUnitsQuotient = adyenGiftcardsConfiguration.totalInMinorUnits/adyenGiftcardsConfiguration.totalPrice;
        this.giftcardDiscount = (adyenGiftcardsConfiguration.giftcardDiscount / this.minorUnitsQuotient).toFixed(2);
        this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);
        this.remainingGiftcardBalance = (adyenGiftcardsConfiguration.giftcardBalance / this.minorUnitsQuotient).toFixed(2);

        this.shoppingCartSummaryBlock = DomAccess.querySelectorAll(document, '.checkout-aside-summary-list');
        this.offCanvasSummaryDetails = null;
        this.shoppingCartSummaryDetails = null;

        this.giftcardComponentClose.onclick = function (event) {
            event.currentTarget.style.display = 'none';
            self.selectedGiftcard = null;
            self.giftcardHeader.innerHTML = ' ';
            if (self.paymentMethodInstance) {
                self.paymentMethodInstance.unmount();
            }
        };

        this.removeGiftcardButton.onclick = function (event) {
            self.removeGiftcard();
        };

        window.addEventListener("DOMContentLoaded", (event) => {
            if (parseInt(adyenGiftcardsConfiguration.giftcardDiscount, 10)) {
                this.onGiftcardSelected();
            }
        });
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
        let self = this;

        for (let i=0; i < this.adyenGiftcard.length; i++) {
            this.adyenGiftcard[i].onclick = function() {
                self.selectedGiftcard = JSON.parse(event.currentTarget.dataset.giftcard);
                self.mountGiftcardComponent(self.selectedGiftcard.extensions.adyenGiftcardData[0]);
            }
        }
    }

    mountGiftcardComponent(giftcard) {
        if (this.giftcardDiscount > 0) {
            return;
        }
        if (this.paymentMethodInstance) {
            this.paymentMethodInstance.unmount();
        }
        ElementLoadingIndicatorUtil.create(DomAccess.querySelector(document, '#adyen-giftcard-component'));
        this.giftcardHeader.innerHTML = giftcard.name;
        this.giftcardComponentClose.style.display = 'block';
        const giftcardConfiguration = Object.assign({}, giftcard, {
            showPayButton: true,
            onBalanceCheck: this.handleBalanceCheck.bind(this),
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
        let params = {};
        params.paymentMethod = JSON.stringify(data.paymentMethod);
        params.amount = JSON.stringify({
            "currency":adyenGiftcardsConfiguration.currency,
            "value": adyenGiftcardsConfiguration.totalInMinorUnits
        });
        this._client.post(
            `${adyenGiftcardsConfiguration.checkBalanceUrl}`,
            JSON.stringify(params),
            function (response) {
                response = JSON.parse(response);
                if (!response.hasOwnProperty('pspReference')) {
                    console.error(response.resultCode);
                    reject(response.resultCode);
                } else {
                    // 0. compare balance to total amount to be paid
                    const consumableBalance = response.transactionLimit ? parseFloat(response.transactionLimit.value) : parseFloat(response.balance.value);
                    let remainingGiftcardBalanceMinorUnits = (consumableBalance - adyenGiftcardsConfiguration.totalInMinorUnits);
                    if (consumableBalance >= adyenGiftcardsConfiguration.totalInMinorUnits) {
                        this.remainingGiftcardBalance = (remainingGiftcardBalanceMinorUnits / this.minorUnitsQuotient).toFixed(2);
                        this.setGiftcardAsPaymentMethod(data, remainingGiftcardBalanceMinorUnits);
                    } else {
                        this.remainingAmount = ((adyenGiftcardsConfiguration.totalInMinorUnits - consumableBalance) / this.minorUnitsQuotient).toFixed(2);
                        this.saveGiftcardStateData(data, consumableBalance.toString(), 0, this.selectedGiftcard.id);
                    }

                    this.remainingBalanceField.style.display = 'block';
                    let innerHtmlBalance = adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingBalance + ': ' +
                        adyenGiftcardsConfiguration.currencySymbol + this.remainingGiftcardBalance;

                    this.remainingBalanceField.innerHTML = innerHtmlBalance;
                }
            }.bind(this)
        );
    }

    saveGiftcardStateData(stateData, amountInMinorUnits, balance, paymentMethodId) {
        // save state data to database, set giftcard as payment method and proceed to checkout
        stateData = JSON.stringify(stateData);
        this._client.post(adyenGiftcardsConfiguration.setGiftcardUrl, JSON.stringify({ stateData, amount: amountInMinorUnits, balance, paymentMethodId }), function (response) {
            response = JSON.parse(response);
            if ('paymentMethodId' in response) {
                this.giftcardDiscount = (amountInMinorUnits / this.minorUnitsQuotient).toFixed(2);
                this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);
                this.onGiftcardSelected();
                ElementLoadingIndicatorUtil.remove(document.body);
            }
        }.bind(this));
    }

    setGiftcardAsPaymentMethod(stateData, balance) {
        this._client.patch(adyenGiftcardsConfiguration.switchContextUrl, JSON.stringify({paymentMethodId: this.selectedGiftcard.id}), function (response) {
            this.saveGiftcardStateData(stateData, adyenGiftcardsConfiguration.totalInMinorUnits, balance, this.selectedGiftcard.id)
        }.bind(this));
    }

    removeGiftcard() {
        ElementLoadingIndicatorUtil.create(document.body);
        this._client.post(adyenGiftcardsConfiguration.removeGiftcardUrl, new FormData, function(response) {
            response = JSON.parse(response);
            if ('token' in response) {
                this.giftcardDiscount = 0;
                this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);

                if (this.shoppingCartSummaryBlock.length) {
                    let giftcardSummary = this.shoppingCartSummaryBlock[0].querySelectorAll('.adyen-giftcard-summary');
                    for (let i = 0; i < giftcardSummary.length; i++) {
                        giftcardSummary[i].remove();
                    }
                }
                this.removeGiftcardButton.style.display = 'none';
                this.remainingBalanceField.style.display = 'none';
                // Show giftcards
                for(var i=0;i<this.adyenGiftcard.length;i++){
                    this.adyenGiftcard[i].style.display = '';
                }
                ElementLoadingIndicatorUtil.remove(document.body);
            }
        }.bind(this));
    }

    appendGiftcardSummary() {
        if(this.shoppingCartSummaryBlock.length) {
            let innerHtmlShoppingCartSummaryDetails = '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.translationAdyenGiftcardDiscount + '</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.giftcardDiscount + '</dd>' +
                '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingAmount + '</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.remainingAmount + '</dd>';

            this.shoppingCartSummaryBlock[0].innerHTML += innerHtmlShoppingCartSummaryDetails;
        }
    }

    onGiftcardSelected() {
        // Remove component
        if (this.paymentMethodInstance) {
            this.paymentMethodInstance.unmount();
        }
        this.giftcardComponentClose.style.display = 'none';
        // Hide giftcards
        // this.adyenGiftcard.style.display = 'none';

        for(var i=0;i<this.adyenGiftcard.length;i++){
            this.adyenGiftcard[i].style.display = 'none';
        }

        this.giftcardHeader.innerHTML = ' ';

        this.appendGiftcardSummary();
        // Show Remove button
        this.removeGiftcardButton.style.display = 'block';
        this.remainingBalanceField.style.display = 'block'

        let balanceInnerHtml = adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingBalance + ': ' +
            adyenGiftcardsConfiguration.currencySymbol + this.remainingGiftcardBalance

        this.remainingBalanceField.innerHTML = balanceInnerHtml;
    }
}
