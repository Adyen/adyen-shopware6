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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

const { Component } = Shopware;
import template from './adyen-refund.html.twig';

Component.register('adyen-refund', {
    template,

    inject: ['adyenService'],

    props: {
        order: {
            type: Object,
            required: true
        },
    },

    data() {
        return {
            showModal: false,
            refunds: []
        };
    },

    methods: {
        openModal() {
            this.showModal = true;
            console.log(this.refunds);
            this.fetchRefunds();
        },

        onCloseModal() {
            console.log(this.refunds);
            this.showModal = false;
        },

        fetchRefunds() {
            this.refunds = this.adyenService.getRefunds(this.order.orderNumber);
        }
    }
})
