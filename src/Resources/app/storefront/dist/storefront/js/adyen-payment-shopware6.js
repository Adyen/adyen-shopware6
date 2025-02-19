"use strict";(self.webpackChunk=self.webpackChunk||[]).push([["adyen-payment-shopware6"],{8914:(e,t,n)=>{var a=n(6285),i=n(3206),o=n(8254),r=n(4690);class s extends a.Z{init(){let e=this;this._client=new o.Z,this.adyenCheckout=Promise,this.paymentMethodInstance=null,this.selectedGiftcard=null,this.initializeCheckoutComponent().then(function(){this.observeGiftcardSelection()}.bind(this)),this.adyenGiftcardDropDown=i.Z.querySelectorAll(document,"#giftcardDropdown"),this.adyenGiftcard=i.Z.querySelectorAll(document,".adyen-giftcard"),this.giftcardHeader=i.Z.querySelector(document,".adyen-giftcard-header"),this.giftcardItem=i.Z.querySelector(document,".adyen-giftcard-item"),this.giftcardComponentClose=i.Z.querySelector(document,".adyen-close-giftcard-component"),this.minorUnitsQuotient=adyenGiftcardsConfiguration.totalInMinorUnits/adyenGiftcardsConfiguration.totalPrice,this.giftcardDiscount=adyenGiftcardsConfiguration.giftcardDiscount,this.remainingAmount=(adyenGiftcardsConfiguration.totalPrice-this.giftcardDiscount).toFixed(2),this.remainingGiftcardBalance=(adyenGiftcardsConfiguration.giftcardBalance/this.minorUnitsQuotient).toFixed(2),this.shoppingCartSummaryBlock=i.Z.querySelectorAll(document,".checkout-aside-summary-list"),this.offCanvasSummaryDetails=null,this.shoppingCartSummaryDetails=null,this.giftcardComponentClose.onclick=function(t){t.currentTarget.style.display="none",e.selectedGiftcard=null,e.giftcardItem.innerHTML="",e.giftcardHeader.innerHTML=" ",e.paymentMethodInstance&&e.paymentMethodInstance.unmount()},document.getElementById("showGiftcardButton").addEventListener("click",(function(){this.style.display="none",document.getElementById("giftcardDropdown").style.display="block"})),window.addEventListener("DOMContentLoaded",(()=>{document.getElementById("giftcardsContainer").addEventListener("click",(e=>{if(e.target.classList.contains("adyen-remove-giftcard")){const t=e.target.getAttribute("dataid");this.removeGiftcard(t)}}))})),window.addEventListener("DOMContentLoaded",(e=>{parseInt(adyenGiftcardsConfiguration.giftcardDiscount,10)&&this.fetchRedeemedGiftcards()}))}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,a={locale:e,clientKey:t,environment:n,amount:{currency:adyenGiftcardsConfiguration.currency,value:adyenGiftcardsConfiguration.totalInMinorUnits}};this.adyenCheckout=await AdyenCheckout(a)}observeGiftcardSelection(){let e=this,t=document.getElementById("giftcardDropdown"),n=document.querySelector(".btn-outline-info");t.addEventListener("change",(function(){t.value&&(e.selectedGiftcard=JSON.parse(event.currentTarget.options[event.currentTarget.selectedIndex].dataset.giftcard),e.mountGiftcardComponent(e.selectedGiftcard),t.value="",n.style.display="none")}))}mountGiftcardComponent(e){this.paymentMethodInstance&&this.paymentMethodInstance.unmount(),this.giftcardItem.innerHTML="",r.Z.create(i.Z.querySelector(document,"#adyen-giftcard-component"));var t=document.createElement("img");t.src="https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/"+e.brand+".svg",t.classList.add("adyen-giftcard-logo"),this.giftcardItem.insertBefore(t,this.giftcardItem.firstChild),this.giftcardHeader.innerHTML=e.name,this.giftcardComponentClose.style.display="block";const n=Object.assign({},e,{showPayButton:!0,onBalanceCheck:this.handleBalanceCheck.bind(this,e)});try{this.paymentMethodInstance=this.adyenCheckout.create("giftcard",n),this.paymentMethodInstance.mount("#adyen-giftcard-component")}catch(e){console.log("giftcard not available")}r.Z.remove(i.Z.querySelector(document,"#adyen-giftcard-component"))}handleBalanceCheck(e,t,n,a){let i={};i.paymentMethod=JSON.stringify(a.paymentMethod),i.amount=JSON.stringify({currency:adyenGiftcardsConfiguration.currency,value:adyenGiftcardsConfiguration.totalInMinorUnits}),this._client.post(`${adyenGiftcardsConfiguration.checkBalanceUrl}`,JSON.stringify(i),function(t){if((t=JSON.parse(t)).hasOwnProperty("pspReference")){const n=t.transactionLimit?parseFloat(t.transactionLimit.value):parseFloat(t.balance.value);a.giftcard={currency:adyenGiftcardsConfiguration.currency,value:(n/this.minorUnitsQuotient).toFixed(2),title:e.name},this.saveGiftcardStateData(a)}else n(t.resultCode)}.bind(this))}fetchRedeemedGiftcards(){this._client.get(adyenGiftcardsConfiguration.fetchRedeemedGiftcardsUrl,function(e){e=JSON.parse(e);let t=document.getElementById("giftcardsContainer"),n=document.querySelector(".btn-outline-info");t.innerHTML="",e.redeemedGiftcards.giftcards.forEach((function(e){let n=parseFloat(e.deductedAmount);n=n.toFixed(2);let a=adyenGiftcardsConfiguration.translationAdyenGiftcardDeductedBalance+": "+adyenGiftcardsConfiguration.currencySymbol+n,i=document.createElement("div");var o=document.createElement("img");o.src="https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/"+e.brand+".svg",o.classList.add("adyen-giftcard-logo");let r=document.createElement("a");r.href="#",r.textContent=adyenGiftcardsConfiguration.translationAdyenGiftcardRemove,r.setAttribute("dataid",e.stateDataId),r.classList.add("adyen-remove-giftcard"),r.style.display="block",i.appendChild(o),i.innerHTML+=`<span>${e.title}</span>`,i.appendChild(r),i.innerHTML+=`<p class="adyen-giftcard-summary">${a}</p> <hr>`,t.appendChild(i)})),this.remainingAmount=e.redeemedGiftcards.remainingAmount,this.giftcardDiscount=e.redeemedGiftcards.totalDiscount,this.paymentMethodInstance&&this.paymentMethodInstance.unmount(),this.giftcardComponentClose.style.display="none",this.giftcardItem.innerHTML="",this.giftcardHeader.innerHTML=" ",this.appendGiftcardSummary(),this.remainingAmount>0?n.style.display="block":(this.adyenGiftcardDropDown.length>0&&(this.adyenGiftcardDropDown[0].style.display="none"),n.style.display="none");document.getElementById("giftcardsContainer")}.bind(this))}saveGiftcardStateData(e){e=JSON.stringify(e),this._client.post(adyenGiftcardsConfiguration.setGiftcardUrl,JSON.stringify({stateData:e}),function(e){"token"in(e=JSON.parse(e))&&(this.fetchRedeemedGiftcards(),r.Z.remove(document.body))}.bind(this))}removeGiftcard(e){r.Z.create(document.body),this._client.post(adyenGiftcardsConfiguration.removeGiftcardUrl,JSON.stringify({stateDataId:e}),(e=>{"token"in(e=JSON.parse(e))&&(this.fetchRedeemedGiftcards(),r.Z.remove(document.body))}))}appendGiftcardSummary(){if(this.shoppingCartSummaryBlock.length){let e=this.shoppingCartSummaryBlock[0].querySelectorAll(".adyen-giftcard-summary");for(let t=0;t<e.length;t++)e[t].remove()}if(this.shoppingCartSummaryBlock.length){let e=parseFloat(this.giftcardDiscount).toFixed(2),t=parseFloat(this.remainingAmount).toFixed(2);this.shoppingCartSummaryBlock[0].innerHTML+="";let n='<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.translationAdyenGiftcardDiscount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.currencySymbol+e+'</dd><dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.translationAdyenGiftcardRemainingAmount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenGiftcardsConfiguration.currencySymbol+t+"</dd>";this.shoppingCartSummaryBlock[0].innerHTML+=n}}}var d=n(207);const c={updatablePaymentMethods:["scheme","ideal","sepadirectdebit","oneclick","bcmc","bcmc_mobile","blik","klarna_b2b","eps","facilypay_3x","facilypay_4x","facilypay_6x","facilypay_10x","facilypay_12x","afterpay_default","ratepay","ratepay_directdebit","giftcard","paybright","affirm","multibanco","mbway","vipps","mobilepay","wechatpayQR","wechatpayWeb","paybybank"],componentsWithPayButton:{applepay:{extra:{},onClick(e,t,n){return n.confirmOrderForm.checkValidity()?(e(),!0):(t(),!1)}},googlepay:{extra:{buttonSizeMode:"fill"},onClick:function(e,t,n){return n.confirmOrderForm.checkValidity()?(e(),!0):(t(),!1)},onError:function(e,t,n){"CANCELED"!==e.statusCode&&("statusMessage"in e?console.log(e.statusMessage):console.log(e.statusCode))}},paypal:{extra:{},onClick:function(e,t,n){return n.confirmOrderForm.checkValidity()},onError:function(e,t,n){t.setStatus("ready"),window.location.href=n.errorUrl.toString()},onCancel:function(e,t,n){t.setStatus("ready"),window.location.href=n.errorUrl.toString()},responseHandler:function(e,t){try{if((t=JSON.parse(t)).isFinal)return void(window.location.href=e.returnUrl);if(!t.action)return void window.location.reload();t.pspReference&&(e.pspReference=t.pspReference),e.paymentData=null,t.action.paymentData&&(e.paymentData=t.action.paymentData),this.handleAction(t.action)}catch(e){console.error(e)}}},amazonpay:{extra:{productType:"PayAndShip",checkoutMode:"ProcessOrder",returnUrl:location.href},prePayRedirect:!0,sessionKey:"amazonCheckoutSessionId",onClick:function(e,t,n){return n.confirmOrderForm.checkValidity()?(e(),!0):(t(),!1)},onError:(e,t)=>{console.log(e),t.setStatus("ready")}}},paymentMethodTypeHandlers:{scheme:"handler_adyen_cardspaymentmethodhandler",ideal:"handler_adyen_idealpaymentmethodhandler",klarna:"handler_adyen_klarnapaylaterpaaymentmethodhandler",klarna_account:"handler_adyen_klarnaaccountpaymentmethodhandler",klarna_paynow:"handler_adyen_klarnapaynowpaymentmethodhandler",ratepay:"handler_adyen_ratepaypaymentmethodhandler",ratepay_directdebit:"handler_adyen_ratepaydirectdebitpaymentmethodhandler",sepadirectdebit:"handler_adyen_sepapaymentmethodhandler",directEbanking:"handler_adyen_klarnadebitriskpaymentmethodhandler",paypal:"handler_adyen_paypalpaymentmethodhandler",oneclick:"handler_adyen_oneclickpaymentmethodhandler",giropay:"handler_adyen_giropaypaymentmethodhandler",applepay:"handler_adyen_applepaypaymentmethodhandler",googlepay:"handler_adyen_googlepaypaymentmethodhandler",bcmc:"handler_adyen_bancontactcardpaymentmethodhandler",bcmc_mobile:"handler_adyen_bancontactmobilepaymentmethodhandler",amazonpay:"handler_adyen_amazonpaypaymentmethodhandler",twint:"handler_adyen_twintpaymentmethodhandler",eps:"handler_adyen_epspaymentmethodhandler",swish:"handler_adyen_swishpaymentmethodhandler",alipay:"handler_adyen_alipaypaymentmethodhandler",alipay_hk:"handler_adyen_alipayhkpaymentmethodhandler",blik:"handler_adyen_blikpaymentmethodhandler",clearpay:"handler_adyen_clearpaypaymentmethodhandler",facilypay_3x:"handler_adyen_facilypay3xpaymentmethodhandler",facilypay_4x:"handler_adyen_facilypay4xpaymentmethodhandler",facilypay_6x:"handler_adyen_facilypay6xpaymentmethodhandler",facilypay_10x:"handler_adyen_facilypay10xpaymentmethodhandler",facilypay_12x:"handler_adyen_facilypay12xpaymentmethodhandler",afterpay_default:"handler_adyen_afterpaydefaultpaymentmethodhandler",trustly:"handler_adyen_trustlypaymentmethodhandler",paysafecard:"handler_adyen_paysafecardpaymentmethodhandler",giftcard:"handler_adyen_giftcardpaymentmethodhandler",mbway:"handler_adyen_mbwaypaymentmethodhandler",multibanco:"handler_adyen_multibancopaymentmethodhandler",wechatpayQR:"handler_adyen_wechatpayqrpaymentmethodhandler",wechatpayWeb:"handler_adyen_wechatpaywebpaymentmethodhandler",mobilepay:"handler_adyen_mobilepaypaymentmethodhandler",vipps:"handler_adyen_vippspaymentmethodhandler",affirm:"handler_adyen_affirmpaymentmethodhandler",paybright:"handler_adyen_paybrightpaymentmethodhandler",paybybank:"handler_adyen_openbankingpaymentmethodhandler",klarna_b2b:"handler_adyen_billiepaymentmethodhandler",ebanking_FI:"handler_adyen_onlinebankingfinlandpaymentmethodhandler",onlineBanking_PL:"handler_adyen_onlinebankingpolandpaymentmethodhandler"}};class l extends a.Z{init(){this._client=new o.Z,this.selectedAdyenPaymentMethod=this.getSelectedPaymentMethodKey(),this.confirmOrderForm=i.Z.querySelector(document,"#confirmOrderForm"),this.confirmFormSubmit=i.Z.querySelector(document,'#confirmOrderForm button[type="submit"]'),this.shoppingCartSummaryBlock=i.Z.querySelectorAll(document,".checkout-aside-summary-list"),this.minorUnitsQuotient=adyenCheckoutOptions.amount/adyenCheckoutOptions.totalPrice,this.giftcardDiscount=adyenCheckoutOptions.giftcardDiscount,this.remainingAmount=adyenCheckoutOptions.totalPrice-this.giftcardDiscount,this.responseHandler=this.handlePaymentAction,this.adyenCheckout=Promise,this.initializeCheckoutComponent().then(function(){adyenCheckoutOptions.selectedPaymentMethodPluginId===adyenCheckoutOptions.adyenPluginId&&(adyenCheckoutOptions&&adyenCheckoutOptions.paymentStatusUrl&&adyenCheckoutOptions.checkoutOrderUrl&&adyenCheckoutOptions.paymentHandleUrl?(this.selectedAdyenPaymentMethod in c.componentsWithPayButton&&this.initializeCustomPayButton(),"klarna_b2b"!==this.selectedAdyenPaymentMethod&&c.updatablePaymentMethods.includes(this.selectedAdyenPaymentMethod)&&!this.stateData?this.renderPaymentComponent(this.selectedAdyenPaymentMethod):this.confirmFormSubmit.addEventListener("click",this.onConfirmOrderSubmit.bind(this))):console.error("Adyen payment configuration missing."))}.bind(this)),adyenCheckoutOptions.payInFullWithGiftcard>0?parseInt(adyenCheckoutOptions.giftcardDiscount,10)&&this.appendGiftcardSummary():this.appendGiftcardSummary()}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n,merchantAccount:a}=adyenCheckoutConfiguration,i=adyenCheckoutOptions.paymentMethodsResponse,o={locale:e,clientKey:t,environment:n,showPayButton:this.selectedAdyenPaymentMethod in c.componentsWithPayButton,hasHolderName:!0,paymentMethodsResponse:JSON.parse(i),onAdditionalDetails:this.handleOnAdditionalDetails.bind(this),countryCode:activeShippingAddress.country,paymentMethodsConfiguration:{card:{hasHolderName:!0,holderNameRequired:!0,clickToPayConfiguration:{merchantDisplayName:a,shopperEmail:shopperDetails.shopperEmail}}}};this.adyenCheckout=await AdyenCheckout(o)}handleOnAdditionalDetails(e){this._client.post(`${adyenCheckoutOptions.paymentDetailsUrl}`,JSON.stringify({orderId:this.orderId,stateData:JSON.stringify(e.data)}),function(e){200===this._client._request.status?this.responseHandler(e):location.href=this.errorUrl.toString()}.bind(this))}onConfirmOrderSubmit(e){const t=i.Z.querySelector(document,"#confirmOrderForm");if(!t.checkValidity())return;if("klarna_b2b"===this.selectedAdyenPaymentMethod){const t=i.Z.querySelector(document,"#adyen-company-name"),n=t?t.value.trim():"",a=i.Z.querySelector(document,"#adyen-company-name-error");a.style.display="none";let o=!1;if(n||(a.style.display="block",o=!0),o)return void e.preventDefault()}e.preventDefault(),r.Z.create(document.body);const n=d.Z.serialize(t);this.confirmOrder(n)}renderPaymentComponent(e){if("oneclick"===e)return void this.renderStoredPaymentMethodComponents();if("giftcard"===e)return;let t=this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter((function(t){return t.type===e}));if(0===t.length)return void("test"===this.adyenCheckout.options.environment&&console.error("Payment method configuration not found. ",e));let n=t[0];this.mountPaymentComponent(n,!1)}renderStoredPaymentMethodComponents(){this.adyenCheckout.paymentMethodsResponse.storedPaymentMethods.forEach((e=>{let t=`[data-adyen-stored-payment-method-id="${e.id}"]`;this.mountPaymentComponent(e,!0,t)})),this.hideStorePaymentMethodComponents();let e=null;i.Z.querySelectorAll(document,"[name=adyenStoredPaymentMethodId]").forEach((t=>{e||(e=t.value),t.addEventListener("change",this.showSelectedStoredPaymentMethod.bind(this))})),this.showSelectedStoredPaymentMethod(null,e)}showSelectedStoredPaymentMethod(e,t=null){this.hideStorePaymentMethodComponents();let n=`[data-adyen-stored-payment-method-id="${t=e?e.target.value:t}"]`;i.Z.querySelector(document,n).style.display="block"}hideStorePaymentMethodComponents(){i.Z.querySelectorAll(document,".stored-payment-component").forEach((e=>{e.style.display="none"}))}confirmOrder(e,t={}){const n=adyenCheckoutOptions.orderId;e.set("affiliateCode",adyenCheckoutOptions.affiliateCode),e.set("campaignCode",adyenCheckoutOptions.campaignCode),n?this.updatePayment(e,n,t):this.createOrder(e,t)}updatePayment(e,t,n){e.set("orderId",t),this._client.post(adyenCheckoutOptions.updatePaymentUrl,e,this.afterSetPayment.bind(this,n))}createOrder(e,t){this._client.post(adyenCheckoutOptions.checkoutOrderUrl,e,this.afterCreateOrder.bind(this,t))}afterCreateOrder(e={},t){let n;try{n=JSON.parse(t)}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}if(n.url)return void(location.href=n.url);if(this.orderId=n.id,this.finishUrl=new URL(location.origin+adyenCheckoutOptions.paymentFinishUrl),this.finishUrl.searchParams.set("orderId",n.id),this.errorUrl=new URL(location.origin+adyenCheckoutOptions.paymentErrorUrl),this.errorUrl.searchParams.set("orderId",n.id),"handler_adyen_billiepaymentmethodhandler"===adyenCheckoutOptions.selectedPaymentMethodHandler){const t=i.Z.querySelector(document,"#adyen-company-name"),n=t?t.value:"",a=i.Z.querySelector(document,"#adyen-registration-number"),o=a?a.value:"";e.companyName=n,e.registrationNumber=o}let a={orderId:this.orderId,finishUrl:this.finishUrl.toString(),errorUrl:this.errorUrl.toString()};for(const t in e)a[t]=e[t];this._client.post(adyenCheckoutOptions.paymentHandleUrl,JSON.stringify(a),this.afterPayOrder.bind(this,this.orderId))}afterSetPayment(e={},t){try{JSON.parse(t).success&&this.afterCreateOrder(e,JSON.stringify({id:adyenCheckoutOptions.orderId}))}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}}afterPayOrder(e,t){try{t=JSON.parse(t),this.returnUrl=t.redirectUrl}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}this.returnUrl===this.errorUrl.toString()&&(location.href=this.returnUrl);try{this._client.post(`${adyenCheckoutOptions.paymentStatusUrl}`,JSON.stringify({orderId:e}),this.responseHandler.bind(this))}catch(e){console.log(e)}}handlePaymentAction(e){try{const t=JSON.parse(e);if((t.isFinal||"voucher"===t.action.type)&&(location.href=this.returnUrl),t.action){const e={};"threeDS2"===t.action.type&&(e.challengeWindowSize="05"),this.adyenCheckout.createFromAction(t.action,e).mount("[data-adyen-payment-action-container]");if(["threeDS2","qrCode"].includes(t.action.type))if("undefined"!=typeof bootstrap&&"function"==typeof bootstrap.Modal){new bootstrap.Modal(document.getElementById("adyen-payment-action-modal"),{keyboard:!1}).show()}else window.jQuery&&"function"==typeof $.fn.modal?$("[data-adyen-payment-action-modal]").modal({show:!0}):console.error("No modal implementation found. Please check your setup.")}}catch(e){console.log(e)}}initializeCustomPayButton(){const e=c.componentsWithPayButton[this.selectedAdyenPaymentMethod];this.completePendingPayment(this.selectedAdyenPaymentMethod,e);let t=this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter((e=>e.type===this.selectedAdyenPaymentMethod));if(t.length<1&&"googlepay"===this.selectedAdyenPaymentMethod&&(t=this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter((e=>"paywithgoogle"===e.type))),t.length<1)return;let n=t[0];if(!adyenCheckoutOptions.amount)return void console.error("Failed to fetch Cart/Order total amount.");if(e.prePayRedirect)return void this.renderPrePaymentButton(e,n);const a=Object.assign(e.extra,n,{amount:{value:adyenCheckoutOptions.amount,currency:adyenCheckoutOptions.currency},data:{personalDetails:shopperDetails,billingAddress:activeBillingAddress,deliveryAddress:activeShippingAddress},onClick:(t,n)=>{if(!e.onClick(t,n,this))return!1;r.Z.create(document.body)},onSubmit:function(t,n){if(t.isValid){let a={stateData:JSON.stringify(t.data)},i=d.Z.serialize(this.confirmOrderForm);"responseHandler"in e&&(this.responseHandler=e.responseHandler.bind(n,this)),this.confirmOrder(i,a)}else n.showValidation(),"test"===this.adyenCheckout.options.environment&&console.log("Payment failed: ",t)}.bind(this),onCancel:(t,n)=>{r.Z.remove(document.body),e.onCancel(t,n,this)},onError:(t,n)=>{"PayPal"!==n.props.name?(r.Z.remove(document.body),e.onError(t,n,this),console.log(t)):this._client.post(`${adyenCheckoutOptions.cancelOrderTransactionUrl}`,JSON.stringify({orderId:this.orderId}),(()=>{r.Z.remove(document.body),e.onError(t,n,this)}))}});"paywithgoogle"!==n.type&&"googlepay"!==n.type||""===adyenCheckoutOptions.googleMerchantId||""===adyenCheckoutOptions.gatewayMerchantId||(a.configuration={merchantId:adyenCheckoutOptions.googleMerchantId,gatewayMerchantId:adyenCheckoutOptions.gatewayMerchantId});const i=this.adyenCheckout.create(n.type,a);try{"isAvailable"in i?i.isAvailable().then(function(){this.mountCustomPayButton(i)}.bind(this)).catch((e=>{console.log(n.type+" is not available",e)})):this.mountCustomPayButton(i)}catch(e){console.log(e)}}renderPrePaymentButton(e,t){"amazonpay"===t.type&&(e.extra=this.setAddressDetails(e.extra));const n=Object.assign(e.extra,t,{configuration:t.configuration,amount:{value:adyenCheckoutOptions.amount,currency:adyenCheckoutOptions.currency},onClick:(t,n)=>{if(!e.onClick(t,n,this))return!1;r.Z.create(document.body)},onError:(t,n)=>{r.Z.remove(document.body),e.onError(t,n,this),console.log(t)}});let a=this.adyenCheckout.create(t.type,n);this.mountCustomPayButton(a)}completePendingPayment(e,t){const n=new URL(location.href);if(n.searchParams.has(t.sessionKey)){r.Z.create(document.body);const a=this.adyenCheckout.create(e,{[t.sessionKey]:n.searchParams.get(t.sessionKey),showOrderButton:!1,onSubmit:function(e,t){if(e.isValid){let t={stateData:JSON.stringify(e.data)},n=d.Z.serialize(this.confirmOrderForm);this.confirmOrder(n,t)}}.bind(this)});this.mountCustomPayButton(a),a.submit()}}getSelectedPaymentMethodKey(){return Object.keys(c.paymentMethodTypeHandlers).find((e=>c.paymentMethodTypeHandlers[e]===adyenCheckoutOptions.selectedPaymentMethodHandler))}mountCustomPayButton(e){let t=document.querySelector("#confirmOrderForm");if(t){let n=t.querySelector("button[type=submit]");if(n&&!n.disabled){let a=document.createElement("div");a.id="adyen-confirm-button",a.setAttribute("data-adyen-confirm-button",""),t.appendChild(a),e.mount(a),n.remove()}}}mountPaymentComponent(e,t=!1,n=null){const a=Object.assign({},e,{data:{personalDetails:shopperDetails,billingAddress:activeBillingAddress,deliveryAddress:activeShippingAddress},onSubmit:function(n,a){if(n.isValid){t&&void 0!==e.holderName&&(n.data.paymentMethod.holderName=e.holderName);let a={stateData:JSON.stringify(n.data)},i=d.Z.serialize(this.confirmOrderForm);r.Z.create(document.body),this.confirmOrder(i,a)}else a.showValidation(),"test"===this.adyenCheckout.options.environment&&console.log("Payment failed: ",n)}.bind(this)});!t&&"scheme"===e.type&&adyenCheckoutOptions.displaySaveCreditCardOption&&(a.enableStoreDetails=!0);let o=t?n:"#"+this.el.id;try{const t=this.adyenCheckout.create(e.type,a);t.mount(o),this.confirmFormSubmit.addEventListener("click",function(e){i.Z.querySelector(document,"#confirmOrderForm").checkValidity()&&(e.preventDefault(),this.el.parentNode.scrollIntoView({behavior:"smooth",block:"start"}),t.submit())}.bind(this))}catch(t){return console.error(e.type,t),!1}}appendGiftcardSummary(){if(parseInt(adyenCheckoutOptions.giftcardDiscount,10)&&this.shoppingCartSummaryBlock.length){let e=parseFloat(this.giftcardDiscount).toFixed(2),t=parseFloat(this.remainingAmount).toFixed(2),n='<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.translationAdyenGiftcardDiscount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.currencySymbol+e+'</dd><dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.translationAdyenGiftcardRemainingAmount+'</dt><dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">'+adyenCheckoutOptions.currencySymbol+t+"</dd>";this.shoppingCartSummaryBlock[0].innerHTML+=n}}setAddressDetails(e){return""!==activeShippingAddress.phoneNumber?e.addressDetails={name:shopperDetails.firstName+" "+shopperDetails.lastName,addressLine1:activeShippingAddress.street,city:activeShippingAddress.city,postalCode:activeShippingAddress.postalCode,countryCode:activeShippingAddress.country,phoneNumber:activeShippingAddress.phoneNumber}:e.productType="PayOnly",e}}class h extends a.Z{init(){this._client=new o.Z,this.paymentMethodInstance=null,this.responseHandler=this.handlePaymentAction,this.userLoggedIn="true"===adyenExpressCheckoutOptions.userLoggedIn,this.formattedHandlerIdentifier="",this.newAddress={},this.newShippingMethod={},this.email="",this.pspReference="",this.paymentData=null,this.blockPayPalShippingOptionChange=!1,this.stateData={};this.paymentMethodSpecificConfig={paywithgoogle:{onClick:(e,t)=>{this.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.googlepay,e()},isExpress:!0,callbackIntents:this.userLoggedIn?[]:["SHIPPING_ADDRESS","PAYMENT_AUTHORIZATION","SHIPPING_OPTION"],shippingAddressRequired:!this.userLoggedIn,emailRequired:!this.userLoggedIn,shippingAddressParameters:{allowedCountryCodes:[],phoneNumberRequired:!0},shippingOptionRequired:!this.userLoggedIn,buttonSizeMode:"fill",onAuthorized:e=>{},buttonColor:"white",paymentDataCallbacks:this.userLoggedIn?{}:{onPaymentDataChanged:e=>new Promise((async t=>{try{const{callbackTrigger:n,shippingAddress:a,shippingOptionData:i}=e,o={};if("INITIALIZE"===n||"SHIPPING_ADDRESS"===n){const e={};a&&(this.newAddress=e.newAddress=a),e.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.googlepay;const t=await this.fetchExpressCheckoutConfig(adyenExpressCheckoutOptions.expressCheckoutConfigUrl,e),n=t.shippingMethodsResponse,i=[];n.forEach((e=>{i.push({id:e.id,label:e.label,description:e.description})})),o.newShippingOptionParameters={defaultSelectedOptionId:i[0].id,shippingOptions:i},o.newTransactionInfo={currencyCode:t.currency,totalPriceStatus:"FINAL",totalPrice:(parseInt(t.amount)/100).toString(),totalPriceLabel:"Total",countryCode:t.countryCode}}if("SHIPPING_OPTION"===n){const e={};a&&(this.newAddress=e.newAddress=a),i&&(this.newShippingMethod=e.newShippingMethod=i),e.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.googlepay;const t=await this.fetchExpressCheckoutConfig(adyenExpressCheckoutOptions.expressCheckoutConfigUrl,e);o.newTransactionInfo={currencyCode:t.currency,totalPriceStatus:"FINAL",totalPrice:(parseInt(t.amount)/100).toString(),totalPriceLabel:"Total",countryCode:t.countryCode}}t(o)}catch(e){console.error("Error in onPaymentDataChanged:",e),t({error:e.error})}})),onPaymentAuthorized:e=>{let t={state:e.shippingAddress.administrativeArea,zipcode:e.shippingAddress.postalCode,street:e.shippingAddress.address1,address2:e.shippingAddress.address2,address3:e.shippingAddress.address3,city:e.shippingAddress.locality,countryCode:e.shippingAddress.countryCode,firstName:"",lastName:""};return this.email=e.email,this.newAddress=t,this.newShippingMethod=e.shippingOptionData,new Promise((e=>{e({transactionState:"SUCCESS"})}))}}}},this.userLoggedIn||(this.paymentMethodSpecificConfig.paypal={isExpress:!0,onShopperDetails:this.onShopperDetails.bind(this),blockPayPalCreditButton:!0,blockPayPalPayLaterButton:!0,onShippingAddressChange:this.onShippingAddressChanged.bind(this),onShippingOptionsChange:this.onShippingOptionsChange.bind(this)},this.paymentMethodSpecificConfig.applepay={isExpress:!0,requiredBillingContactFields:["postalAddress"],requiredShippingContactFields:["postalAddress","name","phoneticName","phone","email"],onAuthorized:this.handleApplePayAuthorization.bind(this),onShippingContactSelected:this.onShippingContactSelected.bind(this),onShippingMethodSelected:this.onShippingMethodSelected.bind(this)}),this.quantityInput=document.querySelector(".product-detail-quantity-select")||document.querySelector(".product-detail-quantity-input"),this.listenOnQuantityChange(),this.mountExpressCheckoutComponents({countryCode:adyenExpressCheckoutOptions.countryCode,amount:adyenExpressCheckoutOptions.amount,currency:adyenExpressCheckoutOptions.currency,paymentMethodsResponse:JSON.parse(adyenExpressCheckoutOptions.paymentMethodsResponse)})}async fetchExpressCheckoutConfig(e,t={}){const n=document.querySelector('meta[itemprop="productID"]'),a=n?n.content:"-1";return new Promise(((n,i)=>{this._client.post(e,JSON.stringify({quantity:this.quantityInput?this.quantityInput.value:-1,productId:a,...t}),(e=>{try{const t=JSON.parse(e);if(this._client._request.status>=400)return void i({error:t.error});n(t)}catch(e){i({status:500,message:"Failed to parse server response."})}}))}))}mountExpressCheckoutComponents(e){if(!document.getElementById("adyen-express-checkout"))return;let t=document.getElementsByClassName("adyen-express-checkout-element");if(0===t.length)return;let n=[],a=e.paymentMethodsResponse.paymentMethods||[];for(let e=0;e<a.length;e++)n[e]=a[e].type;for(let a=0;a<t.length;a++){let i=t[a].getElementsByClassName("adyen-type")[0].value;n.includes(i)&&this.initializeCheckoutComponent(e).then(function(e){this.mountElement(i,e,t[a])}.bind(this))}}mountElement(e,t,n){let a=this.paymentMethodSpecificConfig[e]||null;"applepay"===e&&a&&(a.countryCode=t.options.countryCode),"paywithgoogle"!==e&&"googlepay"!==e||""===adyenExpressCheckoutOptions.googleMerchantId||""===adyenExpressCheckoutOptions.gatewayMerchantId||(a.configuration={merchantId:adyenExpressCheckoutOptions.googleMerchantId,gatewayMerchantId:adyenExpressCheckoutOptions.gatewayMerchantId}),t.create(e,a).mount(n)}async initializeCheckoutComponent(e){const{locale:t,clientKey:n,environment:a}=adyenCheckoutConfiguration,i={locale:t,clientKey:n,environment:a,showPayButton:!0,countryCode:e.countryCode,amount:{value:e.amount,currency:e.currency},paymentMethodsResponse:e.paymentMethodsResponse,onAdditionalDetails:this.handleOnAdditionalDetails.bind(this),onError:(e,t)=>{let n=c.componentsWithPayButton.googlepay;"PayPal"===t.props.name&&"CANCEL"===e.name&&(this._client.post(`${adyenExpressCheckoutOptions.cancelOrderTransactionUrl}`,JSON.stringify({orderId:this.orderId})),n=c.componentsWithPayButton.paypal),r.Z.remove(document.body),n.onError(e,t,this),console.log(e)},onSubmit:function(e,t){if(!e.isValid)return;const n=e.data.paymentMethod.type;if("applepay"===n){if(!this.userLoggedIn)return void(this.stateData=e.data);this.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.applepay}const a=document.querySelector('meta[itemprop="productID"]'),i=a?a.content:"-1",o=this.quantityInput?this.quantityInput.value:-1;if("paypal"===n){this.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.paypal;const e=c.componentsWithPayButton.paypal;"responseHandler"in e&&(this.responseHandler=e.responseHandler.bind(t,this))}const r={productId:i,quantity:o,formattedHandlerIdentifier:this.formattedHandlerIdentifier,newAddress:this.newAddress,newShippingMethod:this.newShippingMethod,email:this.email,affiliateCode:adyenExpressCheckoutOptions.affiliateCode,campaignCode:adyenExpressCheckoutOptions.campaignCode};let s={stateData:JSON.stringify(e.data)};this.createOrder(JSON.stringify(r),s)}.bind(this)};return Promise.resolve(await AdyenCheckout(i))}createOrder(e,t){this._client.post(adyenExpressCheckoutOptions.checkoutOrderExpressUrl,e,this.afterCreateOrder.bind(this,t))}afterCreateOrder(e={},t){let n;try{n=JSON.parse(t)}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}if(n.url)return void(location.href=n.url);this.orderId=n.id,this.finishUrl=new URL(location.origin+adyenExpressCheckoutOptions.paymentFinishUrl),this.finishUrl.searchParams.set("orderId",this.orderId),this.errorUrl=new URL(location.origin+adyenExpressCheckoutOptions.paymentErrorUrl),this.errorUrl.searchParams.set("orderId",this.orderId);let a="";n.customerId&&(a=n.customerId);let i={orderId:this.orderId,finishUrl:this.finishUrl.toString(),errorUrl:this.errorUrl.toString(),customerId:a};for(const t in e)i[t]=e[t];this._client.post(adyenExpressCheckoutOptions.paymentHandleExpressUrl,JSON.stringify(i),this.afterPayOrder.bind(this,this.orderId))}afterPayOrder(e,t){try{t=JSON.parse(t),this.returnUrl=t.redirectUrl}catch(e){return r.Z.remove(document.body),void console.log("Error: invalid response from Shopware API",t)}this.returnUrl===this.errorUrl.toString()&&(location.href=this.returnUrl);try{this._client.post(`${adyenExpressCheckoutOptions.paymentStatusUrl}`,JSON.stringify({orderId:e}),this.responseHandler.bind(this))}catch(e){console.log(e)}}handlePaymentAction(e){try{const t=JSON.parse(e);(t.isFinal||"voucher"===t.action.type)&&(location.href=this.returnUrl)}catch(e){console.log(e)}}handleOnAdditionalDetails(e){this._client.post(`${adyenExpressCheckoutOptions.paymentDetailsUrl}`,JSON.stringify({orderId:this.orderId,stateData:JSON.stringify(e.data),newAddress:this.newAddress,newShipping:this.newShippingMethod}),function(e){200===this._client._request.status?this.responseHandler(e):location.href=this.errorUrl.toString()}.bind(this))}listenOnQuantityChange(){this.quantityInput&&this.quantityInput.addEventListener("change",(e=>{const t=e.target.value,n=document.querySelector('meta[itemprop="productID"]'),a=n?n.content:"-1";this._client.post(adyenExpressCheckoutOptions.expressCheckoutConfigUrl,JSON.stringify({quantity:t,productId:a}),this.afterQuantityUpdated.bind(this))}))}afterQuantityUpdated(e){try{const t=JSON.parse(e);this.mountExpressCheckoutComponents({countryCode:t.countryCode,amount:t.amount,currency:t.currency,paymentMethodsResponse:JSON.parse(t.paymentMethodsResponse)})}catch(e){window.location.reload()}}async onShippingAddressChanged(e,t,n){this.blockPayPalShippingOptionChange=!1,this.newShippingMethod={};const a=n.paymentData,i=e.shippingAddress,o=this.getDataForPayPalCallbacks();return o.currentPaymentData=a,i&&(this.newAddress=o.newAddress=i),new Promise(((t,a)=>{this._client.post(`${adyenExpressCheckoutOptions.expressCheckoutUpdatePaypalOrderUrl}`,JSON.stringify(o),function(i){try{const o=JSON.parse(i);o&&200===this._client._request.status?(n.updatePaymentData(o.paymentData),t()):(this.blockPayPalShippingOptionChange=!0,a(e.errors.COUNTRY_ERROR))}catch(t){this.blockPayPalShippingOptionChange=!0,a(e.errors.COUNTRY_ERROR)}}.bind(this))}))}async onShippingOptionsChange(e,t,n){if(!0===this.blockPayPalShippingOptionChange)return t.reject(e.errors.METHOD_UNAVAILABLE);const a=n.paymentData,i=e.selectedShippingOption,o=this.getDataForPayPalCallbacks();return o.currentPaymentData=a,i&&(this.newShippingMethod=o.newShippingMethod=i),new Promise(((t,a)=>{this._client.post(`${adyenExpressCheckoutOptions.expressCheckoutUpdatePaypalOrderUrl}`,JSON.stringify(o),function(i){try{const o=JSON.parse(i);o&&200===this._client._request.status?(n.updatePaymentData(o.paymentData),t()):a(e.errors.METHOD_UNAVAILABLE)}catch(t){a(e.errors.METHOD_UNAVAILABLE)}}.bind(this))}))}onShopperDetails(e,t,n){this.newAddress={firstName:e.shopperName.firstName,lastName:e.shopperName.lastName,street:e.shippingAddress.street,postalCode:e.shippingAddress.postalCode,city:e.shippingAddress.city,countryCode:e.shippingAddress.country,phoneNumber:e.telephoneNumber,email:e.shopperEmail},n.resolve()}getDataForPayPalCallbacks(){const e={};return e.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.paypal,e.pspReference=this.pspReference,e.orderId=this.orderId,e}handleApplePayAuthorization(e,t,n){let a=n.payment.shippingContact;const i={firstName:a.givenName,lastName:a.familyName,street:a.addressLines.length>0?a.addressLines[0]:"",zipcode:a.postalCode,city:a.locality,countryCode:a.countryCode,phoneNumber:a.phoneNumber,email:a.emailAddress};i&&(this.newAddress=i,this.email=i.email),this.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.applepay;const o=document.querySelector('meta[itemprop="productID"]'),r=o?o.content:"-1",s=this.quantityInput?this.quantityInput.value:-1;let d={stateData:JSON.stringify(this.stateData)};const l={productId:r,quantity:s,formattedHandlerIdentifier:this.formattedHandlerIdentifier,newAddress:this.newAddress,newShippingMethod:this.newShippingMethod,affiliateCode:adyenExpressCheckoutOptions.affiliateCode,campaignCode:adyenExpressCheckoutOptions.campaignCode,email:this.email};this.createOrder(JSON.stringify(l),d)}async onShippingContactSelected(e,t,n){const a=n.shippingContact,i={firstName:"Temp",lastName:"Temp",street:"Street 123",city:a.locality,state:a.administrativeArea,countryCode:a.countryCode,postalCode:a.postalCode},o=this.getDataForApplePayCallbacks();i&&(this.newAddress=o.newAddress=i),this.getApplePayExpressCheckoutConfiguration(e,t,o)}async onShippingMethodSelected(e,t,n){const a={id:n.shippingMethod.identifier},i=this.getDataForApplePayCallbacks();a&&(this.newShippingMethod=i.newShippingMethod=a),this.getApplePayExpressCheckoutConfiguration(e,t,i)}getApplePayExpressCheckoutConfiguration(e,t,n){let a=0,i={};this._client.post(adyenExpressCheckoutOptions.expressCheckoutConfigUrl,JSON.stringify({...n}),function(t){try{const n=JSON.parse(t);if(n&&200===this._client._request.status){a=parseInt(n.amount)/100,i.newTotal={type:"final",label:"Total amount",amount:a.toString()};const t=n.shippingMethodsResponse,o=[];t.forEach((e=>{o.push({identifier:e.id,label:e.label,detail:e.description,amount:parseInt(e.value)/100,selected:e.selected})})),i.newShippingMethods=o,e(i)}else{let t={newTotal:{type:"final",label:"Total amount",amount:a.toString()},errors:[new ApplePayError("shippingContactInvalid","countryCode","Error message")]};e(t)}}catch(t){let n={newTotal:{type:"final",label:"Total amount",amount:a.toString()},errors:[new ApplePayError("shippingContactInvalid","countryCode","Error message")]};e(n)}}.bind(this))}getDataForApplePayCallbacks(){const e={},t=document.querySelector('meta[itemprop="productID"]'),n=t?t.content:"-1",a=this.quantityInput?this.quantityInput.value:-1;return e.formattedHandlerIdentifier=c.paymentMethodTypeHandlers.applepay,e.productId=n,e.quantity=a,e}}class p extends a.Z{init(){this._client=new o.Z,this.adyenCheckout=Promise,this.initializeCheckoutComponent().bind(this)}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,{currency:a,values:i,backgroundUrl:o,logoUrl:r,name:s,description:d,url:c}=adyenGivingConfiguration,l={locale:e,clientKey:t,environment:n},h={amounts:{currency:a,values:i.split(",").map((e=>Number(e)))},backgroundUrl:o,logoUrl:r,description:d,name:s,url:c,showCancelButton:!0,onDonate:this.handleOnDonate.bind(this),onCancel:this.handleOnCancel.bind(this)};this.adyenCheckout=await AdyenCheckout(l),this.adyenCheckout.create("donation",h).mount("#donation-container")}handleOnDonate(e,t){const n=adyenGivingConfiguration.orderId;let a={stateData:JSON.stringify(e.data),orderId:n};a.returnUrl=window.location.href,this._client.post(`${adyenGivingConfiguration.donationEndpointUrl}`,JSON.stringify({...a}),function(e){200!==this._client._request.status?t.setStatus("error"):t.setStatus("success")}.bind(this))}handleOnCancel(){let e=adyenGivingConfiguration.continueActionUrl;window.location=e}}class y extends a.Z{init(){this.adyenCheckout=Promise,this.initializeCheckoutComponent().bind(this)}async initializeCheckoutComponent(){const{locale:e,clientKey:t,environment:n}=adyenCheckoutConfiguration,{action:a}=adyenSuccessActionConfiguration,i={locale:e,clientKey:t,environment:n};this.adyenCheckout=await AdyenCheckout(i),this.adyenCheckout.createFromAction(JSON.parse(a)).mount("#success-action-container")}}const u=window.PluginManager;u.register("CartPlugin",s,"#adyen-giftcards-container"),u.register("ConfirmOrderPlugin",l,"#adyen-payment-checkout-mask"),u.register("ExpressCheckoutPlugin",h,"#adyen-express-checkout"),u.register("AdyenGivingPlugin",p,"#adyen-giving-container"),u.register("AdyenSuccessAction",y,"#adyen-success-action-container")}},e=>{e.O(0,["vendor-node","vendor-shared"],(()=>{return t=8914,e(e.s=t);var t}));e.O()}]);