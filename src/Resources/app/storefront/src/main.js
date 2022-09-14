// Import all necessary Storefront plugins and scss files
import ConfirmOrderPlugin from './checkout/confirm-order.plugin';
import AdyenGivingPlugin from './finish/adyen-giving.plugin';
import AdyenSuccessAction from './finish/adyen-success-action.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '#adyen-payment-checkout-mask');
PluginManager.register('AdyenGivingPlugin', AdyenGivingPlugin, '#adyen-giving-container');
PluginManager.register('AdyenSuccessAction', AdyenSuccessAction, '#adyen-success-action-container');
