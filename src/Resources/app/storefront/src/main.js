// Import all necessary Storefront plugins and scss files
import CheckoutPlugin from './checkout/checkout.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('CheckoutPlugin', CheckoutPlugin, '[data-adyen-payment]');
