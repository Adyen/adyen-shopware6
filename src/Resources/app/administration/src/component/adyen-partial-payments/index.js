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
import VersionHelper from '../../helper/versionHelper';

Component.register('adyen-partial-payments', {
    template,

    inject: ['adyenService'],

    props: {
        order: {
            type: Object,
            required: true
        },
    },

    methods: {
        fetchAdyenPartialPayments() {
            this.adyenService.fetchAdyenPartialPayments(this.order.id).then((res) => {
                if (res.length > 0) {
                    this.partialPayments = res;
                } else {
                    this.errorMessage = this.$tc('adyen.pendingWebhook')
                }
            });
        }
    },

    data() {
        return {
            errorMessage: "",
            partialPayments: [],
            showWidget: false,
            isVersionOlderThan65: false
        }
    },

    beforeMount() {
        this.isVersionOlderThan65 = VersionHelper.isVersionOlderThan65();
        this.showWidget = this.adyenService.isAdyenOrder(this.order);

        if (this.showWidget) {
            this.fetchAdyenPartialPayments();
        }
    }
});
