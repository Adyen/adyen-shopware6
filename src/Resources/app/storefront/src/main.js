// Import all necessary Storefront plugins and scss files
import CartPlugin from './cart/cart.plugin';
import ConfirmOrderPlugin from './checkout/confirm-order.plugin';
import AdyenGivingPlugin from './finish/adyen-giving.plugin';
import AdyenSuccessAction from './finish/adyen-success-action.plugin';
import ExpressCheckoutPlugin from "./express-checkout/express-checkout.plugin";

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('CartPlugin', CartPlugin, '#adyen-giftcards-container');
PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '#adyen-payment-checkout-mask');
PluginManager.register('ExpressCheckoutPlugin', ExpressCheckoutPlugin, '#adyen-express-checkout');
PluginManager.register('AdyenGivingPlugin', AdyenGivingPlugin, '#adyen-giving-container');
PluginManager.register('AdyenSuccessAction', AdyenSuccessAction, '#adyen-success-action-container');
