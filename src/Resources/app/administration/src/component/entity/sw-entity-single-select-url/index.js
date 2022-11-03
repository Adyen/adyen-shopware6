const { Component } = Shopware;
const { Criteria } = Shopware.Data;

const DEFAULT_HEADLESS = 'default.headless0';

Component.extend('sw-entity-single-select-url', 'sw-entity-single-select', {
    props: {
        criteria: {
            type: Object,
            required: false,
            default() {
                const criteria = new Criteria(1, this.resultLimit);
                criteria.addFilter(Criteria.not(
                    'OR',
                    [Criteria.equals('url', DEFAULT_HEADLESS)],
                ));
                return criteria;
            }
        },
    }
});
