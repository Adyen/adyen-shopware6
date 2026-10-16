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
import template from './adyen-express-checkout-pages.html.twig';
import './adyen-express-checkout-pages.scss';

/**
 * Placement multi-select for one express checkout payment method.
 *
 * Shown only while the enable setting named in "dependsOn" is on. When the merchant switches that setting
 * off, the selected pages are cleared, so enabling it again starts from an empty selection.
 */
Component.register('adyen-express-checkout-pages', {
    template,

    inheritAttrs: false,

    emits: ['update:value'],

    props: {
        value: {
            type: Array,
            required: false,
            default: null
        },
        name: {
            type: String,
            required: false,
            default: ''
        },
        dependsOn: {
            type: String,
            required: true
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false
        }
    },

    data() {
        return {
            lastScope: undefined,
            lastEnabled: undefined
        };
    },

    computed: {
        systemConfigComponent() {
            let systemConfigComponent = this.$parent;
            while (systemConfigComponent && systemConfigComponent.actualConfigData === undefined) {
                systemConfigComponent = systemConfigComponent.$parent;
            }

            return systemConfigComponent;
        },

        enableConfigKey() {
            return this.name.substring(0, this.name.lastIndexOf('.') + 1) + this.dependsOn;
        },

        currentScope() {
            return this.systemConfigComponent ? this.systemConfigComponent.currentSalesChannelId : null;
        },

        ownEnableValue() {
            if (!this.systemConfigComponent) {
                return null;
            }

            const scopeConfig = this.systemConfigComponent.actualConfigData[this.currentScope] || {};
            const value = scopeConfig[this.enableConfigKey];

            return value === undefined ? null : value;
        },

        isExpressCheckoutEnabled() {
            if (!this.systemConfigComponent) {
                return true;
            }

            if (this.ownEnableValue !== null) {
                return !!this.ownEnableValue;
            }

            // Properties NOT set in the sales channel config are inherited from the default config.
            const defaultConfig = this.systemConfigComponent.actualConfigData.null || {};

            return !!defaultConfig[this.enableConfigKey];
        },

        // An enabled method needs at least one location, the configuration cannot be saved without it
        pagesError() {
            if (!this.isExpressCheckoutEnabled || (Array.isArray(this.value) && this.value.length > 0)) {
                return null;
            }

            return { detail: this.$tc('adyen.expressCheckoutPages.required') };
        },

        pageOptions() {
            return [
                { value: 'product', label: this.$tc('adyen.expressCheckoutPages.product') },
                { value: 'cart', label: this.$tc('adyen.expressCheckoutPages.cart') },
                { value: 'offcanvas', label: this.$tc('adyen.expressCheckoutPages.offcanvas') }
            ];
        }
    },

    watch: {
        isExpressCheckoutEnabled() {
            this.onEnabledStateChanged();
        }
    },

    mounted() {
        this.onEnabledStateChanged();
    },

    methods: {
        onEnabledStateChanged() {
            this.toggleFieldVisibility();

            const scope = this.currentScope;
            const enabled = this.isExpressCheckoutEnabled;
            // Only a merchant switching the setting off in this scope clears the pages. The first evaluation,
            // a sales channel switch and a value inherited from the default config must leave them untouched.
            const switchedOff = scope === this.lastScope
                && this.lastEnabled === true
                && enabled === false
                && this.ownEnableValue !== null;

            this.lastScope = scope;
            this.lastEnabled = enabled;

            if (switchedOff && Array.isArray(this.value) && this.value.length > 0) {
                this.onPagesChanged([]);
            }
        },

        toggleFieldVisibility() {
            // The label and the inheritance toggle are rendered by the surrounding inherit wrapper,
            // so the whole wrapper is hidden, not only this component.
            this.$nextTick(() => {
                const wrapper = this.$el && this.$el.closest ? this.$el.closest('.sw-inherit-wrapper') : null;
                if (wrapper) {
                    wrapper.style.display = this.isExpressCheckoutEnabled ? '' : 'none';
                }
            });
        },

        onPagesChanged(pages) {
            this.$emit('update:value', pages);
        }
    }
});
