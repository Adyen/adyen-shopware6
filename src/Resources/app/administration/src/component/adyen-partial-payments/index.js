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
 * Copyright (c) 2023 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

const { Component } = Shopware;
import template from './adyen-partial-payments.html.twig';

Component.register('adyen-partial-payments', {
    template,

    inject: ['adyenService'],

    // methods: {
    //     // fetchAdyenPartialPayments() {
    //     //     this.adyenService.fetchAdyenPartialPayments(this.order.id).then((res) => {
    //     //         this.partialPayments = res;
    //     //     });
    //     // }
    // },

    beforeMount() {
       console.log('plugin works fine!');
    }
});
