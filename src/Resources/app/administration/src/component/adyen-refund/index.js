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

    props: {
        order: {
            type: Object,
            required: true
        },
    },

    data() {
        return {
            showModal: false
        };
    },

    methods: {
        openModal() {
            console.log(this.order);
            this.showModal = true;
        },

        onCloseModal() {
            this.showModal = false;
        }
    }
})
