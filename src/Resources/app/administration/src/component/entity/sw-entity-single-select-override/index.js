const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.extend('sw-entity-single-select-override', 'sw-entity-single-select', {
    props: {
        criteria: {
            type: Object,
            required: false,
            default() {
                const limit = this?.resultLimit ?? 25;
                const criteria = new Criteria(1, limit);
                criteria.addFilter(Criteria.equals('stateMachine.technicalName', 'order_delivery.state'));
                return criteria;
            }
        },
    }
});
