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
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

export default class AdyenSuccessActionPlugin extends Plugin {
    init() {
        this.adyenCheckout = Promise;
        this.initializeCheckoutComponent.bind(this)();
    }

    async initializeCheckoutComponent () {
        const { AdyenCheckout } = window.AdyenWeb;
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;
        const { action } = adyenSuccessActionConfiguration;

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            countryCode: adyenCheckoutConfiguration.countryCode
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
        this.adyenCheckout.createFromAction(JSON.parse(action)).mount('#success-action-container');
    }
}
