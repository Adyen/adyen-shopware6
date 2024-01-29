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
        this.adyenGiftcardDropDown = DomAccess.querySelectorAll(document, '#giftcardDropdown');
        this.adyenGiftcard = DomAccess.querySelectorAll(document, '.adyen-giftcard');
        this.giftcardHeader = DomAccess.querySelector(document, '.adyen-giftcard-header');
        this.giftcardItem = DomAccess.querySelector(document, '.adyen-giftcard-item');
        this.giftcardComponentClose = DomAccess.querySelector(document, '.adyen-close-giftcard-component');
        this.minorUnitsQuotient = adyenGiftcardsConfiguration.totalInMinorUnits/adyenGiftcardsConfiguration.totalPrice;
        this.giftcardDiscount = adyenGiftcardsConfiguration.giftcardDiscount;
        this.remainingAmount = (adyenGiftcardsConfiguration.totalPrice - this.giftcardDiscount).toFixed(2);
        this.remainingGiftcardBalance = (adyenGiftcardsConfiguration.giftcardBalance / this.minorUnitsQuotient).toFixed(2);

        this.shoppingCartSummaryBlock = DomAccess.querySelectorAll(document, '.checkout-aside-summary-list');
        this.offCanvasSummaryDetails = null;
        this.shoppingCartSummaryDetails = null;
debugger;
        this.giftcardComponentClose.onclick = function (event) {
            event.currentTarget.style.display = 'none';
            self.selectedGiftcard = null;
            self.giftcardItem.innerHTML = '';
            self.giftcardHeader.innerHTML = ' ';
            if (self.paymentMethodInstance) {
                self.paymentMethodInstance.unmount();
            }
        };

        window.addEventListener('DOMContentLoaded', () => {
            const giftcardsList = document.getElementById('giftcardsContainer');
            giftcardsList.addEventListener('click', (event) => {
                if (event.target.classList.contains('adyen-remove-giftcard')) {
                    const storeId = event.target.getAttribute('dataid');
                    console.log(storeId);
                    this.removeGiftcard(storeId);
                }
            });
        });

        window.addEventListener("DOMContentLoaded", (event) => {
            if (parseInt(adyenGiftcardsConfiguration.giftcardDiscount, 10)) {
                this.fetchRedeemedGiftcards();
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
            },
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    observeGiftcardSelection() {
        let self = this;
        let giftcardDropdown = document.getElementById('giftcardDropdown');

        giftcardDropdown.addEventListener('change', function () {
            if (giftcardDropdown.value) {
                self.selectedGiftcard = JSON.parse(event.currentTarget.options[event.currentTarget.selectedIndex].dataset.giftcard);
                self.mountGiftcardComponent(self.selectedGiftcard.extensions.adyenGiftcardData[0]);
                giftcardDropdown.value = "";
            }
        });
    }

    mountGiftcardComponent(giftcard) {
        if (this.paymentMethodInstance) {
            this.paymentMethodInstance.unmount();
        }
        this.giftcardItem.innerHTML = '';
        ElementLoadingIndicatorUtil.create(DomAccess.querySelector(document, '#adyen-giftcard-component'));

        //Add Giftcard image and name
        var imgElement = document.createElement('img');
        imgElement.src = 'https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/'+giftcard.brand+'.svg';
        imgElement.classList.add('adyen-giftcard-logo');

        this.giftcardItem.insertBefore(imgElement, this.giftcardItem.firstChild);
        this.giftcardHeader.innerHTML = giftcard.name;

        this.giftcardComponentClose.style.display = 'block';
        const giftcardConfiguration = Object.assign({}, giftcard, {
            showPayButton: true,
            onBalanceCheck: this.handleBalanceCheck.bind(this, giftcard),
        });

        try {
            this.paymentMethodInstance = this.adyenCheckout.create('giftcard', giftcardConfiguration);
            this.paymentMethodInstance.mount('#adyen-giftcard-component');
        } catch (e) {
            console.log('giftcard not available');
        }
        ElementLoadingIndicatorUtil.remove(DomAccess.querySelector(document, '#adyen-giftcard-component'));
    }

    handleBalanceCheck(giftcard, resolve, reject, data) {
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
                    reject(response.resultCode);
                } else {
                    // 0. compare balance to total amount to be paid
                    const consumableBalance = (response.transactionLimit ? parseFloat(response.transactionLimit.value) : parseFloat(response.balance.value));

                    //Saving Currency and the remaining Cart amount and Giftcard Title
                    data.giftcard = {
                        "currency": adyenGiftcardsConfiguration.currency,
                        "value": (consumableBalance / this.minorUnitsQuotient).toFixed(2),
                        "title": giftcard.name
                    };
                    this.saveGiftcardStateData(data);
                }
            }.bind(this)
        );
    }

    fetchRedeemedGiftcards() {
        this._client.get(`${adyenGiftcardsConfiguration.fetchRedeemedGiftcardsUrl}`, function (response) {
            debugger;
            response = JSON.parse(response);
            let totalBalance =0;
            let giftcardsContainer = document.getElementById('giftcardsContainer');

            // Clear the container before adding new content
            giftcardsContainer.innerHTML = '';

            // Iterate through the redeemed gift cards and display them
            response.redeemedGiftcards.giftcards.forEach(function(giftcard) {

                let balanceInnerHtml = adyenGiftcardsConfiguration.translationAdyenGiftcardDeductedBalance + ': ' +
                    adyenGiftcardsConfiguration.currencySymbol + giftcard.deductedAmount;

                //Create a new HTML element for each gift card
                let giftcardElement = document.createElement('div');
                var imgElement = document.createElement('img');
                imgElement.src = 'https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/'+giftcard.brand+'.svg';
                imgElement.classList.add('adyen-giftcard-logo');

                let removeElement = document.createElement('a');
                removeElement.href = 'javascript:void(0)'; // Set the href attribute to '#' or your desired link
                removeElement.textContent = adyenGiftcardsConfiguration.translationAdyenGiftcardRemove;
                removeElement.setAttribute('dataid', giftcard.stateDataId);
                removeElement.classList.add('adyen-remove-giftcard');
                removeElement.style.display = 'block';

                giftcardElement.appendChild(imgElement);
                giftcardElement.innerHTML += `<span>${giftcard.title}</span>`;
                giftcardElement.appendChild(removeElement);
                giftcardElement.innerHTML += `<p class="adyen-giftcard-summary">${balanceInnerHtml}</p> <hr>`;

                // Append the gift card element to the container
                giftcardsContainer.appendChild(giftcardElement);

            });
            //Update calculations
            debugger;
            console.log(this.giftcardDiscount);
            console.log(response.redeemedGiftcards.totalDiscount);
            this.remainingAmount = response.redeemedGiftcards.remainingAmount;
            this.giftcardDiscount = response.redeemedGiftcards.totalDiscount;

            // Remove component
            if (this.paymentMethodInstance) {
                this.paymentMethodInstance.unmount();
            }
            this.giftcardComponentClose.style.display = 'none';
            this.giftcardItem.innerHTML = '';
            this.giftcardHeader.innerHTML = ' ';
            this.appendGiftcardSummary();

            //Compare the new total gift card balance with the order amount
            if (this.remainingAmount > 0.00) {
                //allow adding new giftcards
                if (this.adyenGiftcardDropDown.length > 0) {
                    this.adyenGiftcardDropDown[0].style.display = 'block';
                }
            } else {
                // Hide giftcards dropdown
                if (this.adyenGiftcardDropDown.length > 0) {
                    this.adyenGiftcardDropDown[0].style.display = 'none';
                }
            }
            let giftcardContainerElement = document.getElementById('giftcardsContainer'); // Replace with your actual container ID

        }.bind(this));
    }

    saveGiftcardStateData(stateData) {
        // save state data to database, set giftcard as payment method and proceed to checkout
        stateData = JSON.stringify(stateData);
        this._client.post(
            adyenGiftcardsConfiguration.setGiftcardUrl,
            JSON.stringify({ stateData}),
            function (response) {
                response = JSON.parse(response);
                if ('token' in response) {
                    this.fetchRedeemedGiftcards();
                    ElementLoadingIndicatorUtil.remove(document.body);
                }
            }.bind(this)
        );
    }

    removeGiftcard(storeId) {
        ElementLoadingIndicatorUtil.create(document.body);

        this._client.post(adyenGiftcardsConfiguration.removeGiftcardUrl, JSON.stringify({stateDataId: storeId}), (response) => {
            response = JSON.parse(response);
            if ('token' in response) {
                this.fetchRedeemedGiftcards();
                ElementLoadingIndicatorUtil.remove(document.body);
            }
        });
    }

    appendGiftcardSummary()
    {
        if (this.shoppingCartSummaryBlock.length) {
            let giftcardSummary = this.shoppingCartSummaryBlock[0].querySelectorAll('.adyen-giftcard-summary');
            for (let i = 0; i < giftcardSummary.length; i++) {
                giftcardSummary[i].remove();
            }
        }
        if (this.shoppingCartSummaryBlock.length) {
            this.shoppingCartSummaryBlock[0].innerHTML += '';
            let innerHtmlShoppingCartSummaryDetails = '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.translationAdyenGiftcardDiscount + '</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.giftcardDiscount + '</dd>' +
                '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingAmount + '</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenGiftcardsConfiguration.currencySymbol + this.remainingAmount + '</dd>';

            this.shoppingCartSummaryBlock[0].innerHTML += innerHtmlShoppingCartSummaryDetails;
        }
    }
}
