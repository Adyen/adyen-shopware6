const ADYEN_CONFIG_DOMAIN = 'AdyenPaymentShopware6.config';
const EXPRESS_CHECKOUT_METHODS = [
    { key: 'applePay', label: 'Apple Pay' },
    { key: 'googlePay', label: 'Google Pay' },
    { key: 'payPal', label: 'PayPal' }
];

/**
 * Prevents saving the Adyen configuration while an express checkout method is enabled
 * without any express checkout location selected.
 */
Shopware.Component.override('sw-system-config', {
    methods: {
        saveAll() {
            if (this.domain === ADYEN_CONFIG_DOMAIN && this.getAdyenExpressCheckoutMethodsWithoutPages().length > 0) {
                return Promise.reject(this.$tc('adyen.expressCheckoutPages.saveError'));
            }

            return this.$super('saveAll');
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
