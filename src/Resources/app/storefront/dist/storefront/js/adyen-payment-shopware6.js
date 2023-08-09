"use strict";(self.webpackChunk=self.webpackChunk||[]).push([["adyen-payment-shopware6"],{7815:(e,t,n)=>{var a=n(6285),i=n(3206),o=n(8254),r=n(4690);class d extends a.Z{init(){let e=this;this._client=new o.Z,this.adyenCheckout=Promise,this.paymentMethodInstance=null,this.selectedGiftcard=null,this.initializeCheckoutComponent().then(function(){this.observeGiftcardSelection()}.bind(this)),this.adyenGiftcard=i.Z.querySelectorAll(document,".adyen-giftcard"),this.giftcardHeader=i.Z.querySelector(document,".adyen-giftcard-header"),this.giftcardComponentClose=i.Z.querySelector(document,".adyen-close-giftcard-component"),this.removeGiftcardButton=i.Z.querySelector(document,".adyen-remove-giftcard"),this.remainingBalanceField=i.Z.querySelector(document,".adyen-remaining-balance"),this.minorUnitsQuotient=adyenGiftcardsConfiguration.totalInMinorUnits/adyenGiftcardsConfiguration.totalPrice,this.giftcardDiscount=(adyenGiftcardsConfiguration.giftcardDiscount/this.minorUnitsQuotient).toFixed(2),this.remainingAmount=(adyenGiftcardsConfiguration.totalPrice-this.giftcardDiscount).toFixed(2),this.remainingGiftcardBalance=(adyenGiftcardsConfiguration.giftcardBalance/this.minorUnitsQuotient).toFixed(2),this.shoppingCartSummaryBlock=i.Z.querySelectorAll(document,".checkout-aside-summary-list"),this.offCanvasSummaryDetails=null,this.shoppingCartSummaryDetails=null,this.giftcardComponentClose.onclick=function(t){t.currentTarget.style.display="none",e.selectedGiftcard=null,e.giftcardHeader.innerHTML=" ",e.paymentMethodInstance&&e.paymentMethodInstance.unmount()},this.removeGiftcardButton.onclick=function(t){e.removeGiftcard()},window.addEventListener("DOMContentLoaded",(e=>{parseInt(adyenGiftcardsConfiguration.giftcardDiscount,10)&&this.onGiftcardSelected()}))}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,a={locale:e,clientKey:t,environment:n,amount:{currency:adyenGiftcardsConfiguration.currency,value:adyenGiftcardsConfiguration.totalInMinorUnits}};this.adyenCheckout=await AdyenCheckout(a)}observeGiftcardSelection(){let e=this;for(let t=0;t<this.adyenGiftcard.length;t++)this.adyenGiftcard[t].onclick=function(){e.selectedGiftcard=JSON.parse(event.currentTarget.dataset.giftcard),e.mountGiftcardComponent(e.selectedGiftcard.extensions.adyenGiftcardData[0])}}mountGiftcardComponent(e){if(this.giftcardDiscount>0)return;this.paymentMethodInstance&&this.paymentMethodInstance.unmount(),r.Z.create(i.Z.querySelector(document,"#adyen-giftcard-component")),this.giftcardHeader.innerHTML=e.name,this.giftcardComponentClose.style.display="block";const t=Object.assign({},e,{showPayButton:!0,onBalanceCheck:this.handleBalanceCheck.bind(this)});try{this.paymentMethodInstance=this.adyenCheckout.create("giftcard",t),this.paymentMethodInstance.mount("#adyen-giftcard-component")}catch(e){console.log("giftcard not available")}r.Z.remove(i.Z.querySelector(document,"#adyen-giftcard-component"))}handleBalanceCheck(e,t,n){let a={};a.paymentMethod=JSON.stringify(n.paymentMethod),a.amount=JSON.stringify({currency:adyenGiftcardsConfiguration.currency,value:adyenGiftcardsConfiguration.totalInMinorUnits}),this._client.post(`${adyenGiftcardsConfiguration.checkBalanceUrl}`,JSON.stringify(a),function(e){if((e=JSON.parse(e)).hasOwnProperty("pspReference")){const t=parseFloat(e.balance.value);let a=t-adyenGiftcardsConfiguration.totalInMinorUnits;t>=adyenGiftcardsConfiguration.totalInMinorUnits?(this.remainingGiftcardBalance=(a/this.minorUnitsQuotient).toFixed(2),this.setGiftcardAsPaymentMethod(n,a)):(this.remainingAmount=((adyenGiftcardsConfiguration.totalInMinorUnits-t)/this.minorUnitsQuotient).toFixed(2),this.saveGiftcardStateData(n,t.toString(),0,this.selectedGiftcard.id)),this.remainingBalanceField.style.display="block";let i=adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingBalance+": "+adyenGiftcardsConfiguration.currencySymbol+this.remainingGiftcardBalance;this.remainingBalanceField.innerHTML=i}else console.error(e.resultCode),t(e.resultCode)}.bind(this))}saveGiftcardStateData(e,t,n,a){e=JSON.stringify(e),this._client.post(adyenGiftcardsConfiguration.setGiftcardUrl,JSON.stringify({stateData:e,amount:t,balance:n,paymentMethodId:a}),function(e){"paymentMethodId"in(e=JSON.parse(e))&&(this.giftcardDiscount=(t/this.minorUnitsQuotient).toFixed(2),this.remainingAmount=(adyenGiftcardsConfiguration.totalPrice-this.giftcardDiscount).toFixed(2),this.onGiftcardSelected(),r.Z.remove(document.body))}.bind(this))}setGiftcardAsPaymentMethod(e,t){this._client.patch(adyenGiftcardsConfiguration.switchContextUrl,JSON.stringify({paymentMethodId:this.selectedGiftcard.id}),function(n){this.saveGiftcardStateData(e,adyenGiftcardsConfiguration.totalInMinorUnits,t,this.selectedGiftcard.id)}.bind(this))}removeGiftcard(){r.Z.create(document.body),this._client.post(adyenGiftcardsConfiguration.removeGiftcardUrl,new FormData,function(e){if("token"in(e=JSON.parse(e))){if(this.giftcardDiscount=0,this.remainingAmount=(adyenGiftcardsConfiguration.totalPrice-this.giftcardDiscount).toFixed(2),this.shoppingCartSummaryBlock.length){let e=this.shoppingCartSummaryBlock[0].querySelectorAll(".adyen-giftcard-summary");for(let t=0;t<e.length;t++)e[t].remove()}this.removeGiftcardButton.style.display="none",this.remainingBalanceField.style.display="none";for(var t=0;t<this.adyenGiftcard.length;t++)this.adyenGiftcard[t].style.display="";r.Z.remove(document.body)}}.bind(this))}appendGiftcardSummary(){if(this.shoppingCartSummaryBlock.length){let e='<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.translationAdyenGiftcardDiscount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.currencySymbol+this.giftcardDiscount+'</dd><dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingAmount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.currencySymbol+this.remainingAmount+"</dd>";this.shoppingCartSummaryBlock[0].innerHTML+=e}}onGiftcardSelected(){this.paymentMethodInstance&&this.paymentMethodInstance.unmount(),this.giftcardComponentClose.style.display="none";for(var e=0;e<this.adyenGiftcard.length;e++)this.adyenGiftcard[e].style.display="none";this.giftcardHeader.innerHTML=" ",this.appendGiftcardSummary(),this.removeGiftcardButton.style.display="block",this.remainingBalanceField.style.display="block";let t=adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingBalance+": "+adyenGiftcardsConfiguration.currencySymbol+this.remainingGiftcardBalance;this.remainingBalanceField.innerHTML=t}}var s=n(207);const c={updatablePaymentMethods:["scheme","ideal","sepadirectdebit","oneclick","dotpay","bcmc","bcmc_mobile","blik","eps","facilypay_3x","facilypay_4x","facilypay_6x","facilypay_10x","facilypay_12x","afterpay_default","ratepay","ratepay_directdebit","giftcard","paybright","affirm","multibanco","mbway","vipps","mobilepay","wechatpayQR","wechatpayWeb","paybybank"],componentsWithPayButton:{applepay:{extra:{},onClick(e,t,n){return n.confirmOrderForm.checkValidity()?(e(),!0):(t(),!1)}},googlepay:{extra:{buttonSizeMode:"fill"},onClick:function(e,t,n){return n.confirmOrderForm.checkValidity()?(e(),!0):(t(),!1)},onError:function(e,t,n){"CANCELED"!==e.statusCode&&("statusMessage"in e?console.log(e.statusMessage):console.log(e.statusCode))}},paypal:{extra:{},onClick:function(e,t,n){return n.confirmOrderForm.checkValidity()},onError:function(e,t,n){t.setStatus("ready"),window.location.href=n.errorUrl.toString()},onCancel:function(e,t,n){t.setStatus("ready"),window.location.href=n.errorUrl.toString()},responseHandler:function(e,t){try{(t=JSON.parse(t)).isFinal&&(location.href=e.returnUrl),this.handleAction(t.action)}catch(e){console.error(e)}}},amazonpay:{extra:{productType:"PayAndShip",checkoutMode:"ProcessOrder",returnUrl:location.href},prePayRedirect:!0,sessionKey:"amazonCheckoutSessionId",onClick:function(e,t,n){return n.confirmOrderForm.checkValidity()?(e(),!0):(t(),!1)},onError:(e,t)=>{console.log(e),t.setStatus("ready")}}},paymentMethodTypeHandlers:{scheme:"handler_adyen_cardspaymentmethodhandler",ideal:"handler_adyen_idealpaymentmethodhandler",klarna:"handler_adyen_klarnapaylaterpaymentmethodhandler",klarna_account:"handler_adyen_klarnaaccountpaymentmethodhandler",klarna_paynow:"handler_adyen_klarnapaynowpaymentmethodhandler",ratepay:"handler_adyen_ratepaypaymentmethodhandler",ratepay_directdebit:"handler_adyen_ratepaydirectdebitpaymentmethodhandler",sepadirectdebit:"handler_adyen_sepapaymentmethodhandler",sofort:"handler_adyen_sofortpaymentmethodhandler",paypal:"handler_adyen_paypalpaymentmethodhandler",oneclick:"handler_adyen_oneclickpaymentmethodhandler",giropay:"handler_adyen_giropaypaymentmethodhandler",applepay:"handler_adyen_applepaypaymentmethodhandler",googlepay:"handler_adyen_googlepaypaymentmethodhandler",dotpay:"handler_adyen_dotpaypaymentmethodhandler",bcmc:"handler_adyen_bancontactcardpaymentmethodhandler",bcmc_mobile:"handler_adyen_bancontactmobilepaymentmethodhandler",amazonpay:"handler_adyen_amazonpaypaymentmethodhandler",twint:"handler_adyen_twintpaymentmethodhandler",eps:"handler_adyen_epspaymentmethodhandler",swish:"handler_adyen_swishpaymentmethodhandler",alipay:"handler_adyen_alipaypaymentmethodhandler",alipay_hk:"handler_adyen_alipayhkpaymentmethodhandler",blik:"handler_adyen_blikpaymentmethodhandler",clearpay:"handler_adyen_clearpaypaymentmethodhandler",facilypay_3x:"handler_adyen_facilypay3xpaymentmethodhandler",facilypay_4x:"handler_adyen_facilypay4xpaymentmethodhandler",facilypay_6x:"handler_adyen_facilypay6xpaymentmethodhandler",facilypay_10x:"handler_adyen_facilypay10xpaymentmethodhandler",facilypay_12x:"handler_adyen_facilypay12xpaymentmethodhandler",afterpay_default:"handler_adyen_afterpaydefaultpaymentmethodhandler",trustly:"handler_adyen_trustlypaymentmethodhandler",paysafecard:"handler_adyen_paysafecardpaymentmethodhandler",givex:"handler_adyen_givexgiftcardpaymentmethodhandler",webshopgiftcard:"handler_adyen_webshopgiftcardpaymentmethodhandler",kadowereld:"handler_adyen_kadowereldgiftcardpaymentmethodhandler",tcstestgiftcard:"handler_adyen_tcstestgiftcardpaymentmethodhandler",albelligiftcard:"handler_adyen_albelligiftcardpaymentmethodhandler",bijcadeaucard:"handler_adyen_bijenkorfgiftcardpaymentmethodhandler",vvvgiftcard:"handler_adyen_vvvgiftcardpaymentmethodhandler",genericgiftcard:"handler_adyen_genericgiftcardpaymentmethodhandler",gallgall:"handler_adyen_gallgallgiftcardpaymentmethodhandler",hmlingerie:"handler_adyen_hunkemollerlingeriegiftcardpaymentmethodhandler",beautycadeaukaart:"handler_adyen_beautygiftcardpaymentmethodhandler",svs:"handler_adyen_svsgiftcardpaymentmethodhandler",fashioncheque:"handler_adyen_fashionchequegiftcardpaymentmethodhandler",decadeaukaart:"handler_adyen_decadeaukaartgiftcardpaymentmethodhandler",mbway:"handler_adyen_mbwaypaymentmethodhandler",multibanco:"handler_adyen_multibancopaymentmethodhandler",wechatpayQR:"handler_adyen_wechatpayqrpaymentmethodhandler",wechatpayWeb:"handler_adyen_wechatpaywebpaymentmethodhandler",mobilepay:"handler_adyen_mobilepaypaymentmethodhandler",vipps:"handler_adyen_vippspaymentmethodhandler",affirm:"handler_adyen_affirmpaymentmethodhandler",paybright:"handler_adyen_paybrightpaymentmethodhandler",paybybank:"handler_adyen_openbankingpaymentmethodhandler"}};class l extends a.Z{init(){this._client=new o.Z,this.selectedAdyenPaymentMethod=this.getSelectedPaymentMethodKey(),this.confirmOrderForm=i.Z.querySelector(document,"#confirmOrderForm"),this.confirmFormSubmit=i.Z.querySelector(document,'#confirmOrderForm button[type="submit"]'),this.shoppingCartSummaryBlock=i.Z.querySelectorAll(document,".checkout-aside-summary-list"),this.minorUnitsQuotient=adyenCheckoutOptions.amount/adyenCheckoutOptions.totalPrice,this.giftcardDiscount=(adyenCheckoutOptions.giftcardDiscount/this.minorUnitsQuotient).toFixed(2),this.remainingAmount=(adyenCheckoutOptions.totalPrice-this.giftcardDiscount).toFixed(2),this.responseHandler=this.handlePaymentAction,this.adyenCheckout=Promise,this.initializeCheckoutComponent().then(function(){adyenCheckoutOptions.selectedPaymentMethodPluginId===adyenCheckoutOptions.adyenPluginId&&(adyenCheckoutOptions&&adyenCheckoutOptions.paymentStatusUrl&&adyenCheckoutOptions.checkoutOrderUrl&&adyenCheckoutOptions.paymentHandleUrl?(this.selectedAdyenPaymentMethod in c.componentsWithPayButton&&this.initializeCustomPayButton(),c.updatablePaymentMethods.includes(this.selectedAdyenPaymentMethod)&&!this.stateData?this.renderPaymentComponent(this.selectedAdyenPaymentMethod):this.confirmFormSubmit.addEventListener("click",this.onConfirmOrderSubmit.bind(this))):console.error("Adyen payment configuration missing."))}.bind(this)),parseInt(adyenCheckoutOptions.payInFullWithGiftcard,10)?parseInt(adyenCheckoutOptions.adyenGiftcardSelected,10)&&this.appendGiftcardSummary():this.appendGiftcardSummary()}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,a=adyenCheckoutOptions.paymentMethodsResponse,i={locale:e,clientKey:t,environment:n,showPayButton:this.selectedAdyenPaymentMethod in c.componentsWithPayButton,hasHolderName:!0,paymentMethodsResponse:JSON.parse(a),onAdditionalDetails:this.handleOnAdditionalDetails.bind(this),countryCode:activeShippingAddress.country,paymentMethodsConfiguration:{card:{hasHolderName:!0}}};this.adyenCheckout=await AdyenCheckout(i)}handleOnAdditionalDetails(e){this._client.post(`${adyenCheckoutOptions.paymentDetailsUrl}`,JSON.stringify({orderId:this.orderId,stateData:JSON.stringify(e.data)}),function(e){200===this._client._request.status?this.responseHandler(e):location.href=this.errorUrl.toString()}.bind(this))}onConfirmOrderSubmit(e){const t=i.Z.querySelector(document,"#confirmOrderForm");if(!t.checkValidity())return;e.preventDefault(),r.Z.create(document.body);const n=s.Z.serialize(t);this.confirmOrder(n)}renderPaymentComponent(e){if("oneclick"===e)return void this.renderStoredPaymentMethodComponents();let t=this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter((function(t){return t.type===e}));if(0===t.length)return void("test"===this.adyenCheckout.options.environment&&console.error("Payment method configuration not found. ",e));let n=t[0];this.mountPaymentComponent(n,!1)}renderStoredPaymentMethodComponents(){this.adyenCheckout.paymentMethodsResponse.storedPaymentMethods.forEach((e=>{let t=`[data-adyen-stored-payment-method-id="${e.id}"]`;this.mountPaymentComponent(e,!0,t)})),this.hideStorePaymentMethodComponents();let e=null;i.Z.querySelectorAll(document,"[name=adyenStoredPaymentMethodId]").forEach((t=>{e||(e=t.value),t.addEventListener("change",this.showSelectedStoredPaymentMethod.bind(this))})),this.showSelectedStoredPaymentMethod(null,e)}showSelectedStoredPaymentMethod(e,t=null){this.hideStorePaymentMethodComponents();let n=`[data-adyen-stored-payment-method-id="${t=e?e.target.value:t}"]`;i.Z.querySelector(document,n).style.display="block"}hideStorePaymentMethodComponents(){i.Z.querySelectorAll(document,".stored-payment-component").forEach((e=>{e.style.display="none"}))}confirmOrder(e,t={}){const n=adyenCheckoutOptions.orderId;e.set("affiliateCode",adyenCheckoutOptions.affiliateCode),e.set("campaignCode",adyenCheckoutOptions.campaignCode),n?this.updatePayment(e,n,t):this.createOrder(e,t)}updatePayment(e,t,n){e.set("orderId",t),this._client.post(adyenCheckoutOptions.updatePaymentUrl,e,this.afterSetPayment.bind(this,n))}createOrder(e,t){parseInt(adyenCheckoutOptions.giftcardDiscount,10)&&!parseInt(adyenCheckoutOptions.payInFullWithGiftcard,10)?this._client.post(adyenCheckoutOptions.createOrderUrl,JSON.stringify({orderAmount:adyenCheckoutOptions.amount,currency:adyenCheckoutOptions.currency}),function(n){const a=JSON.parse(n);"Success"===a.resultCode&&(t=Object.assign(t,{order:a})),this._client.post(adyenCheckoutOptions.checkoutOrderUrl,e,this.afterCreateOrder.bind(this,t))}.bind(this)):this._client.post(adyenCheckoutOptions.checkoutOrderUrl,e,this.afterCreateOrder.bind(this,t))}afterCreateOrder(e={},t){let n;try{n=JSON.parse(t)}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}this.orderId=n.id,this.finishUrl=new URL(location.origin+adyenCheckoutOptions.paymentFinishUrl),this.finishUrl.searchParams.set("orderId",n.id),this.errorUrl=new URL(location.origin+adyenCheckoutOptions.paymentErrorUrl),this.errorUrl.searchParams.set("orderId",n.id);let a={orderId:this.orderId,finishUrl:this.finishUrl.toString(),errorUrl:this.errorUrl.toString()};for(const t in e)a[t]=e[t];this._client.post(adyenCheckoutOptions.paymentHandleUrl,JSON.stringify(a),this.afterPayOrder.bind(this,this.orderId))}afterSetPayment(e={},t){try{JSON.parse(t).success&&this.afterCreateOrder(e,JSON.stringify({id:adyenCheckoutOptions.orderId}))}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}}afterPayOrder(e,t){try{t=JSON.parse(t),this.returnUrl=t.redirectUrl}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}this.returnUrl===this.errorUrl.toString()&&(location.href=this.returnUrl);try{this._client.post(`${adyenCheckoutOptions.paymentStatusUrl}`,JSON.stringify({orderId:e}),this.responseHandler.bind(this))}catch(e){console.log(e)}}handlePaymentAction(e){try{const t=JSON.parse(e);if((t.isFinal||"voucher"===t.action.type)&&(location.href=this.returnUrl),t.action){const e={};"threeDS2"===t.action.type&&(e.challengeWindowSize="05"),this.adyenCheckout.createFromAction(t.action,e).mount("[data-adyen-payment-action-container]");if(["threeDS2","qrCode"].includes(t.action.type))if(window.jQuery)$("[data-adyen-payment-action-modal]").modal({show:!0});else new bootstrap.Modal(document.getElementById("adyen-payment-action-modal"),{keyboard:!1}).show()}}catch(e){console.log(e)}}initializeCustomPayButton(){const e=c.componentsWithPayButton[this.selectedAdyenPaymentMethod];this.completePendingPayment(this.selectedAdyenPaymentMethod,e);let t=this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter((e=>e.type===this.selectedAdyenPaymentMethod));if(t.length<1&&"googlepay"===this.selectedAdyenPaymentMethod&&(t=this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter((e=>"paywithgoogle"===e.type))),t.length<1)return;let n=t[0];if(!adyenCheckoutOptions.amount)return void console.error("Failed to fetch Cart/Order total amount.");if(e.prePayRedirect)return void this.renderPrePaymentButton(e,n);const a=Object.assign(e.extra,n,{amount:{value:adyenCheckoutOptions.amount,currency:adyenCheckoutOptions.currency},data:{personalDetails:shopperDetails,billingAddress:activeBillingAddress,deliveryAddress:activeShippingAddress},onClick:(t,n)=>{if(!e.onClick(t,n,this))return!1;r.Z.create(document.body)},onSubmit:function(t,n){if(t.isValid){let a={stateData:JSON.stringify(t.data)},i=s.Z.serialize(this.confirmOrderForm);"responseHandler"in e&&(this.responseHandler=e.responseHandler.bind(n,this)),this.confirmOrder(i,a)}else n.showValidation(),"test"===this.adyenCheckout.options.environment&&console.log("Payment failed: ",t)}.bind(this),onCancel:(t,n)=>{r.Z.remove(document.body),e.onCancel(t,n,this)},onError:(t,n)=>{"PayPal"===n.props.name&&"CANCEL"===t.name&&this._client.post(`${adyenCheckoutOptions.cancelOrderTransactionUrl}`,JSON.stringify({orderId:this.orderId})),r.Z.remove(document.body),e.onError(t,n,this),console.log(t)}}),i=this.adyenCheckout.create(n.type,a);try{"isAvailable"in i?i.isAvailable().then(function(){this.mountCustomPayButton(i)}.bind(this)).catch((e=>{console.log(n.type+" is not available",e)})):this.mountCustomPayButton(i)}catch(e){console.log(e)}}renderPrePaymentButton(e,t){"amazonpay"===t.type&&(e.extra=this.setAddressDetails(e.extra));const n=Object.assign(e.extra,t,{configuration:t.configuration,amount:{value:adyenCheckoutOptions.amount,currency:adyenCheckoutOptions.currency},onClick:(t,n)=>{if(!e.onClick(t,n,this))return!1;r.Z.create(document.body)},onError:(t,n)=>{r.Z.remove(document.body),e.onError(t,n,this),console.log(t)}});let a=this.adyenCheckout.create(t.type,n);this.mountCustomPayButton(a)}completePendingPayment(e,t){const n=new URL(location.href);if(n.searchParams.has(t.sessionKey)){r.Z.create(document.body);const a=this.adyenCheckout.create(e,{[t.sessionKey]:n.searchParams.get(t.sessionKey),showOrderButton:!1,onSubmit:function(e,t){if(e.isValid){let t={stateData:JSON.stringify(e.data)},n=s.Z.serialize(this.confirmOrderForm);this.confirmOrder(n,t)}}.bind(this)});this.mountCustomPayButton(a),a.submit()}}getSelectedPaymentMethodKey(){return Object.keys(c.paymentMethodTypeHandlers).find((e=>c.paymentMethodTypeHandlers[e]===adyenCheckoutOptions.selectedPaymentMethodHandler))}mountCustomPayButton(e){let t=document.querySelector("#confirmOrderForm");if(t){let n=t.querySelector("button[type=submit]");if(n&&!n.disabled){let a=document.createElement("div");a.id="adyen-confirm-button",a.setAttribute("data-adyen-confirm-button",""),t.appendChild(a),e.mount(a),n.remove()}}}mountPaymentComponent(e,t=!1,n=null){const a=Object.assign({},e,{data:{personalDetails:shopperDetails,billingAddress:activeBillingAddress,deliveryAddress:activeShippingAddress},onSubmit:function(e,t){if(e.isValid){let t={stateData:JSON.stringify(e.data)},n=s.Z.serialize(this.confirmOrderForm);r.Z.create(document.body),this.confirmOrder(n,t)}else t.showValidation(),"test"===this.adyenCheckout.options.environment&&console.log("Payment failed: ",e)}.bind(this)});!t&&"scheme"===e.type&&adyenCheckoutOptions.displaySaveCreditCardOption&&(a.enableStoreDetails=!0);let o=t?n:"#"+this.el.id;try{const t=this.adyenCheckout.create(e.type,a);t.mount(o),this.confirmFormSubmit.addEventListener("click",function(e){i.Z.querySelector(document,"#confirmOrderForm").checkValidity()&&(e.preventDefault(),this.el.parentNode.scrollIntoView({behavior:"smooth",block:"start"}),t.submit())}.bind(this))}catch(t){return console.error(e.type,t),!1}}appendGiftcardSummary(){if(parseInt(adyenCheckoutOptions.giftcardDiscount,10)&&this.shoppingCartSummaryBlock.length){let e='<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.translationAdyenGiftcardDiscount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.currencySymbol+this.giftcardDiscount+'</dd><dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.translationAdyenGiftcardRemainingAmount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.currencySymbol+this.remainingAmount+"</dd>";this.shoppingCartSummaryBlock[0].innerHTML+=e}}setAddressDetails(e){return""!==activeShippingAddress.phoneNumber?e.addressDetails={name:shopperDetails.firstName+" "+shopperDetails.lastName,addressLine1:activeShippingAddress.street,city:activeShippingAddress.city,postalCode:activeShippingAddress.postalCode,countryCode:activeShippingAddress.country,phoneNumber:activeShippingAddress.phoneNumber}:e.productType="PayOnly",e}}class h extends a.Z{init(){this._client=new o.Z,this.adyenCheckout=Promise,this.initializeCheckoutComponent().bind(this)}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,{currency:a,values:i,backgroundUrl:o,logoUrl:r,name:d,description:s,url:c}=adyenGivingConfiguration,l={locale:e,clientKey:t,environment:n},h={amounts:{currency:a,values:i.split(",").map((e=>Number(e)))},backgroundUrl:o,logoUrl:r,description:s,name:d,url:c,showCancelButton:!0,onDonate:this.handleOnDonate.bind(this),onCancel:this.handleOnCancel.bind(this)};this.adyenCheckout=await AdyenCheckout(l),this.adyenCheckout.create("donation",h).mount("#donation-container")}handleOnDonate(e,t){const n=adyenGivingConfiguration.orderId;let a={stateData:JSON.stringify(e.data),orderId:n};a.returnUrl=window.location.href,this._client.post(`${adyenGivingConfiguration.donationEndpointUrl}`,JSON.stringify({...a}),function(e){200!==this._client._request.status?t.setStatus("error"):t.setStatus("success")}.bind(this))}handleOnCancel(){let e=adyenGivingConfiguration.continueActionUrl;window.location=e}}class y extends a.Z{init(){this.adyenCheckout=Promise,this.initializeCheckoutComponent().bind(this)}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,{action:a}=adyenSuccessActionConfiguration,i={locale:e,clientKey:t,environment:n};this.adyenCheckout=await AdyenCheckout(i),this.adyenCheckout.createFromAction(JSON.parse(a)).mount("#success-action-container")}}const m=window.PluginManager;m.register("CartPlugin",d,"#adyen-giftcards-container"),m.register("ConfirmOrderPlugin",l,"#adyen-payment-checkout-mask"),m.register("AdyenGivingPlugin",h,"#adyen-giving-container"),m.register("AdyenSuccessAction",y,"#adyen-success-action-container")}},e=>{e.O(0,["vendor-node","vendor-shared"],(()=>{return t=7815,e(e.s=t);var t}));e.O()}]);