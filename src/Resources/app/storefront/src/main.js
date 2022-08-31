// Import all necessary Storefront plugins and scss files
import ConfirmOrderPlugin from './checkout/confirm-order.plugin';
import AdyenGivingPlugin from './finish/adyen-giving.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '#adyen-payment-checkout-mask');
PluginManager.register('AdyenGivingPlugin', AdyenGivingPlugin, '#adyen-giving-container');
