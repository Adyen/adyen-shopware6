/*
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 */

const { Component, Mixin } = Shopware;
import template from './adyen-payment-method-capture.html.twig';
import './adyen-payment-method-capture.scss';
import {
    isEmptyCaptureValue,
    isValidCaptureDelayDays,
    isValidCaptureRetryInterval
} from '../../helper/captureSettings';

const ALL_SALES_CHANNELS = 'null';
const GLOBAL = 'global';
const CAPTURE_MODES = ['immediate', 'delayed', 'manual', 'on_shipment'];

/**
 * "Adyen - Capture Settings" card on the payment method detail page (spec §10.2).
 *
 * Works like the plugin configuration page: one Sales Channel switch, and in a sales channel every field can
 * inherit the value of All Sales Channels. The edits of every sales channel are kept until save().
 */
Component.register('adyen-payment-method-capture', {
    template,

    inject: ['adyenService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        paymentMethodId: {
            type: String,
            required: true
        }
    },

    data() {
        return {
            salesChannelId: null,
            scopes: {},
            supported: false,
            isLoading: false
        };
    },

    computed: {
        scopeKey() {
            return this.salesChannelId ?? ALL_SALES_CHANNELS;
        },

        scope() {
            return this.scopes[this.scopeKey] || null;
        },

        isSalesChannel() {
            return this.salesChannelId !== null;
        },

        modeOptions() {
            return [GLOBAL, ...CAPTURE_MODES].map((mode) => ({
                value: mode,
                label: this.getModeLabel(mode)
            }));
        },

        /**
         * Mode of the scope as shown: the own value, otherwise the inherited one. In All Sales Channels an empty
         * value means "Use global configuration".
         */
        modeValue() {
            const mode = this.scope?.values.mode;

            if (mode === null || mode === undefined) {
                return this.isSalesChannel ? null : GLOBAL;
            }

            return mode;
        },

        currentMode() {
            return this.modeValue ?? this.scope?.inherited.mode ?? GLOBAL;
        },

        appliedMode() {
            return this.currentMode === GLOBAL ? this.scope?.globalMode : this.currentMode;
        },

        appliedText() {
            const snippet = this.currentMode === GLOBAL
                ? 'adyen.captureConfiguration.appliedGlobal'
                : 'adyen.captureConfiguration.appliedOwn';

            return this.$t(snippet, { mode: this.getModeLabel(this.appliedMode) });
        }
    },

    watch: {
        paymentMethodId: {
            immediate: true,
            handler() {
                this.salesChannelId = null;
                this.scopes = {};
                this.loadScope();
            }
        }
    },

    methods: {
        getModeLabel(mode) {
            const snippets = {
                global: 'adyen.captureConfiguration.modeGlobal',
                immediate: 'adyen.captureConfiguration.modeImmediate',
                delayed: 'adyen.captureConfiguration.modeDelayed',
                manual: 'adyen.captureConfiguration.modeManual',
                on_shipment: 'adyen.captureConfiguration.modeOnShipment'
            };

            return snippets[mode] ? this.$tc(snippets[mode]) : '';
        },

        loadScope() {
            const scopeKey = this.scopeKey;
            if (this.scopes[scopeKey]) {
                return Promise.resolve();
            }

            this.isLoading = true;

            return this.adyenService.getCaptureConfiguration(this.paymentMethodId, this.salesChannelId)
                .then((response) => {
                    this.supported = !!response.supported;
                    if (!this.supported) {
                        return;
                    }

                    this.scopes = {
                        ...this.scopes,
                        [scopeKey]: {
                            values: { ...response.values },
                            inherited: response.inherited,
                            globalMode: response.globalMode,
                            dirty: false
                        }
                    };
                })
                .catch(() => {
                    this.supported = false;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onSalesChannelChanged(salesChannelId) {
            this.salesChannelId = salesChannelId || null;
            this.loadScope();
        },

        updateField(field, value) {
            if (!this.scope) {
                return;
            }

            this.scope.values[field] = value === undefined || value === '' ? null : value;
            this.scope.dirty = true;
        },

        /**
         * The own delay of a scope must be a whole number from 1 to 7, also when the field is hidden, because it is
         * saved too. An empty value inherits.
         */
        isDelayDaysValid(scope) {
            const delayDays = scope.values.delayDays;

            return isEmptyCaptureValue(delayDays) || isValidCaptureDelayDays(delayDays);
        },

        /**
         * The own retry interval of a scope must be a whole number of at least 1. An empty value inherits.
         */
        isRetryIntervalValid(scope) {
            const retryInterval = scope.values.retryInterval;

            return isEmptyCaptureValue(retryInterval) || isValidCaptureRetryInterval(retryInterval);
        },

        getPlaceholder(field) {
            const inherited = this.scope?.inherited[field];

            return inherited === null || inherited === undefined ? '' : String(inherited);
        },

        /**
         * Saves every changed sales channel. Called by the payment method detail page before it saves.
         */
        save() {
            const dirtyScopes = Object.entries(this.scopes).filter(([, scope]) => scope.dirty);

            // Same checks and messages as the plugin configuration page.
            const error = dirtyScopes.some(([, scope]) => !this.isDelayDaysValid(scope))
                ? 'adyen.captureSettings.delayDaysError'
                : dirtyScopes.some(([, scope]) => !this.isRetryIntervalValid(scope))
                    ? 'adyen.captureSettings.rescheduleTimeError'
                    : null;
            if (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc(error)
                });

                return Promise.reject(new Error('Invalid capture configuration.'));
            }

            return Promise.all(dirtyScopes.map(([scopeKey, scope]) => {
                return this.adyenService.saveCaptureConfiguration(
                    this.paymentMethodId,
                    scopeKey === ALL_SALES_CHANNELS ? null : scopeKey,
                    scope.values
                ).then(() => {
                    scope.dirty = false;
                });
            })).then(() => {
                // Reload, so the inherited values and the applied mode are current.
                this.scopes = {};

                return this.loadScope();
            }).catch((error) => {
                this.createNotificationError({
                    title: this.$tc('adyen.captureConfiguration.title'),
                    message: error?.response?.data?.message || this.$tc('adyen.captureConfiguration.saveError')
                });

                throw error;
            });
        }
    }
});
