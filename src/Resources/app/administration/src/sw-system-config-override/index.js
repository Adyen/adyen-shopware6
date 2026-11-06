import {
    isEmptyCaptureValue,
    isValidCaptureDelayDays,
    isValidCaptureRetryInterval
} from '../helper/captureSettings';

const ADYEN_CONFIG_DOMAIN = 'AdyenPaymentShopware6.config';
const EXPRESS_CHECKOUT_METHODS = [
    { key: 'applePay', label: 'Apple Pay' },
    { key: 'googlePay', label: 'Google Pay' },
    { key: 'payPal', label: 'PayPal' }
];

const CAPTURE_MODE_KEY = `${ADYEN_CONFIG_DOMAIN}.captureMode`;
const CAPTURE_DELAY_DAYS_KEY = `${ADYEN_CONFIG_DOMAIN}.captureDelayDays`;
const ORDER_STATE_KEY = `${ADYEN_CONFIG_DOMAIN}.orderState`;
const RESCHEDULE_TIME_KEY = `${ADYEN_CONFIG_DOMAIN}.rescheduleTime`;
const DEFAULT_CAPTURE_MODE = 'immediate';

/**
 * Capture fields that are only shown for one capture mode (spec §3.2).
 */
const CAPTURE_MODE_FIELDS = {
    [CAPTURE_DELAY_DAYS_KEY]: ['delayed'],
    [ORDER_STATE_KEY]: ['on_shipment'],
    [RESCHEDULE_TIME_KEY]: ['on_shipment']
};

/**
 * Adyen plugin configuration page:
 * - shows the capture fields only for the capture mode they belong to;
 * - prevents saving an incomplete capture configuration;
 * - prevents saving while an express checkout method is enabled without any express checkout location selected.
 */
Shopware.Component.override('sw-system-config', {
    methods: {
        getInheritWrapperBind(element) {
            const bind = this.$super('getInheritWrapperBind', element);

            if (this.domain !== ADYEN_CONFIG_DOMAIN || !CAPTURE_MODE_FIELDS[element.name]) {
                return bind;
            }

            const captureMode = this.getAdyenEffectiveValue(this.currentSalesChannelId, CAPTURE_MODE_KEY)
                || DEFAULT_CAPTURE_MODE;
            if (CAPTURE_MODE_FIELDS[element.name].includes(captureMode)) {
                return bind;
            }

            return { ...bind, style: { display: 'none' } };
        },

        saveAll() {
            if (this.domain === ADYEN_CONFIG_DOMAIN) {
                if (this.getAdyenExpressCheckoutMethodsWithoutPages().length > 0) {
                    return Promise.reject(this.$tc('adyen.expressCheckoutPages.saveError'));
                }

                const captureError = this.getAdyenCaptureSettingsError();
                if (captureError) {
                    return Promise.reject(this.$tc(captureError));
                }
            }

            return this.$super('saveAll');
        },

        /**
         * The value of a scope, or the All Sales Channels value it inherits.
         */
        getAdyenEffectiveValue(scope, key) {
            const scopeConfig = this.actualConfigData[scope] || {};
            const value = scopeConfig[key];

            if (value !== null && value !== undefined && value !== '') {
                return value;
            }

            return (this.actualConfigData.null || {})[key];
        },

        /**
         * Every scope loaded on the page is saved, so every one of them is checked (spec §10.1).
         *
         * @returns {string|null} Snippet key of the error, or null if the capture settings are complete.
         */
        getAdyenCaptureSettingsError() {
            const scopes = Object.keys(this.actualConfigData);

            for (const scope of scopes) {
                const scopeKey = scope === 'null' ? null : scope;
                const captureMode = this.getAdyenEffectiveValue(scopeKey, CAPTURE_MODE_KEY) || DEFAULT_CAPTURE_MODE;
                const ownConfig = this.actualConfigData[scope] || {};

                // A hidden field is saved too, so a value it has must be valid whatever the capture mode is.
                const ownDelayDays = ownConfig[CAPTURE_DELAY_DAYS_KEY];
                if (!isEmptyCaptureValue(ownDelayDays) && !isValidCaptureDelayDays(ownDelayDays)) {
                    return 'adyen.captureSettings.delayDaysError';
                }

                const ownRescheduleTime = ownConfig[RESCHEDULE_TIME_KEY];
                if (!isEmptyCaptureValue(ownRescheduleTime) && !isValidCaptureRetryInterval(ownRescheduleTime)) {
                    return 'adyen.captureSettings.rescheduleTimeError';
                }

                if (captureMode === 'delayed') {
                    if (!isValidCaptureDelayDays(this.getAdyenEffectiveValue(scopeKey, CAPTURE_DELAY_DAYS_KEY))) {
                        return 'adyen.captureSettings.delayDaysError';
                    }
                }

                if (captureMode === 'on_shipment') {
                    if (!this.getAdyenEffectiveValue(scopeKey, ORDER_STATE_KEY)) {
                        return 'adyen.captureSettings.orderStateError';
                    }

                    if (!isValidCaptureRetryInterval(this.getAdyenEffectiveValue(scopeKey, RESCHEDULE_TIME_KEY))) {
                        return 'adyen.captureSettings.rescheduleTimeError';
                    }
                }
            }

            return null;
        },

        getAdyenExpressCheckoutMethodsWithoutPages() {
            const prefix = `${ADYEN_CONFIG_DOMAIN}.`;
            const defaultConfig = this.actualConfigData.null || {};
            const methodsWithoutPages = [];

            // Every scope loaded on the page is saved, so every one of them is checked.
            // Values a sales channel does not set itself, including an empty selection, are inherited.
            Object.keys(this.actualConfigData).forEach((scope) => {
                const scopeConfig = this.actualConfigData[scope] || {};
                const isDefaultScope = scope === 'null';

                EXPRESS_CHECKOUT_METHODS.forEach(({ key, label }) => {
                    const enabled = scopeConfig[`${prefix}${key}ExpressCheckoutEnabled`];
                    const pages = scopeConfig[`${prefix}${key}ExpressCheckoutPages`];

                    const isEnabled = enabled === null || enabled === undefined
                        ? !!defaultConfig[`${prefix}${key}ExpressCheckoutEnabled`]
                        : !!enabled;
                    const effectivePages = isDefaultScope || (Array.isArray(pages) && pages.length > 0)
                        ? pages
                        : defaultConfig[`${prefix}${key}ExpressCheckoutPages`];

                    const hasPages = Array.isArray(effectivePages) && effectivePages.length > 0;
                    if (isEnabled && !hasPages && !methodsWithoutPages.includes(label)) {
                        methodsWithoutPages.push(label);
                    }
                });
            });

            return methodsWithoutPages;
        }
    }
});
