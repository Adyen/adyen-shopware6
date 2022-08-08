// Import all necessary Storefront plugins and scss files
import ConfirmOrderPlugin from './checkout/confirm-order.plugin';
import AdyenGivingPlugin from './finish/adyen-giving.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '[data-adyen-payment]');
PluginManager.register('AdyenGivingPlugin', AdyenGivingPlugin, '[data-adyen-giving]');