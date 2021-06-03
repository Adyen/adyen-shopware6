// Import all necessary Storefront plugins and scss files
import ConfirmOrderPlugin from './checkout/confirm-order.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '[data-adyen-payment]');
