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

const { Component, Mixin } = Shopware;
import template from './adyen-config-check-button.html.twig';

Component.register('adyen-config-check-button', {
    template,
    inheritAttrs: false,

    inject: ['adyenService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false
        }
    },

    computed: {
        filteredAttrs() {
            const { disabled, ...rest } = this.$attrs;
            return rest;
        },

        pluginConfig() {
            // Plugin config moved up by one level in Shopware >= v6.3.4.1
            // i.e. from this.$parent.$parent.$parent to this.$parent.$parent.$parent.$parent
            // @fixme This is a hack to support all versions
            let systemConfigComponent = this.$parent;
            while (systemConfigComponent.actualConfigData === undefined) {
                systemConfigComponent = systemConfigComponent.$parent;
            }
            let selectedSalesChannelId = systemConfigComponent.currentSalesChannelId;
            let config = systemConfigComponent.actualConfigData;
            // Properties NOT set in the sales channel config will be inherited from default config.
            return Object.assign({}, config.null, config[selectedSalesChannelId]);
        }
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        check() {
            this.isLoading = true;
            this.adyenService.check(this.pluginConfig).then((res) => {
                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('adyen.configTestTitle'),
                        message: this.$tc('adyen.configTestSuccess')
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('adyen.configTestTitle'),
                        message: this.$tc(res.message ? res.message : 'adyen.configTestFail')
                    });
                }

                this.isLoading = false;
            });
        }
    }
});
