// Import all necessary Storefront plugins and scss files
import CheckoutPlugin from './checkout/checkout.plugin';
import ConfirmOrderPlugin from './checkout/confirm-order.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('CheckoutPlugin', CheckoutPlugin, '[data-adyen-payment]');
PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '[data-adyen-payment]');
