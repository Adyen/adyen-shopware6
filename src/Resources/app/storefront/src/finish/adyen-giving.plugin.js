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

export default class AdyenGivingPlugin extends Plugin {
    init() {
        this._client = new HttpClient();
        this.adyenCheckout = Promise;
        let boundInitializeCheckout = this.initializeCheckoutComponent.bind(this);
        boundInitializeCheckout();
    }

    async initializeCheckoutComponent () {
        console.log("usao ovde")
        const { AdyenCheckout } = window.AdyenWeb;
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;
        const { currency, values, backgroundUrl,
            logoUrl, name, description, url } = adyenGivingConfiguration;

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            countryCode: adyenCheckoutConfiguration.countryCode
        };

        const ADYEN_GIVING_CONFIG = {
            donation: {
                currency: currency,
                donationType: "fixedAmounts",
                values: values.split(",").map(Number)
            },
            bannerUrl: backgroundUrl,
            logoUrl: logoUrl,
            nonprofitDescription: description,
            nonprofitName: name,
            nonprofitUrl: url,
            termsAndConditionsUrl: "https://www.google.com", // TO DO
            showCancelButton: true,
            onDonate: this.handleOnDonate.bind(this),
            onCancel: this.handleOnCancel.bind(this)
        }

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
        new AdyenWeb.Donation(this.adyenCheckout, ADYEN_GIVING_CONFIG).mount('#donation-container');
    }

    handleOnDonate(state, component) {
        const orderId = adyenGivingConfiguration.orderId;
        let payload = {stateData: JSON.stringify(state.data), orderId};
        payload.returnUrl = window.location.href;

        this._client.post(
            `${adyenGivingConfiguration.donationEndpointUrl}`,
            JSON.stringify({...payload}),
            function (paymentResponse) {
                if (this._client._request.status !== 200) {
                    component.setStatus("error");
                }
                else {
                    component.setStatus("success");
                }
            }.bind(this)
        );
    }

    handleOnCancel() {
        let continueActionUrl = adyenGivingConfiguration.continueActionUrl;
        window.location = continueActionUrl;
    }
}
