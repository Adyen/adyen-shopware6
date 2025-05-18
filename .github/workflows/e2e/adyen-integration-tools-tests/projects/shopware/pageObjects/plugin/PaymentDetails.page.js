import { BancontactCardComponents } from "../../../common/checkoutComponents/BancontactCardComponents.js";
import { CreditCardComponents } from "../../../common/checkoutComponents/CreditCardComponents.js";
import { IDealComponents } from "../../../common/checkoutComponents/iDealComponents.js";
import { OneyComponents } from "../../../common/checkoutComponents/OneyComponents.js";
import { PayPalComponents } from "../../../common/checkoutComponents/PayPalComponents.js";
import { SepaDirectDebitComponents } from "../../../common/checkoutComponents/SepaDirectDebitComponents.js";
import { SPRBasePage } from "./SPRBase.page.js";

export class PaymentDetailsPage extends SPRBasePage {
    constructor(page) {
        super(page)
        this.page = page;

        // Terms and conditions Checkbox
        this.termsAndConditionsCheckBox = page.locator("label[for='tos']");

        // Show More button
        this.showMoreButton = page.locator
            ("//span[@class='confirm-checkout-collapse-trigger-label' and contains(text(),'Show more')]");
        
        // Collapsed payment methods section
        this.collapsedPaymentMethods = page.locator(".collapse.show");

        // Payment Method Specifics
        this.paymentDetailsList = page.locator("#changePaymentForm");
        this.cardSelector = this.paymentDetailsList.locator("img[alt='Cards']");
        this.payPalSelector = this.paymentDetailsList.locator("img[alt='PayPal']");
        this.idealWrapper = this.paymentDetailsList.locator("#adyen-payment-checkout-mask");
        this.idealSelector = this.paymentDetailsList.locator("img[alt='iDeal']");
        this.clearPaySelector = this.paymentDetailsList.locator("img[alt='Clearpay']");
        this.klarnaPayNowSelector = this.paymentDetailsList.locator("img[alt='Klarna Pay Now']");
        this.klarnaPayLaterSelector = this.paymentDetailsList.locator("img[alt='Klarna Pay Later']");
        this.klarnaPayAccountSelector = this.paymentDetailsList.locator("img[alt='Klarna Account']");
        this.sepaDirectDebitWrapper = this.paymentDetailsList.locator(".adyen-checkout__fieldset--iban-input");
        this.sepaDirectDebitSelector = this.paymentDetailsList.locator("img[alt='SEPA direct debit']");
        this.multiBancoSelector = this.paymentDetailsList.locator("img[alt='Multibanco']");
        this.oneySelector = this.paymentDetailsList.locator("img[alt='Oney 3x']");
        this.oneyWrapper = this.oneySelector.locator("..");
        this.bancontactCardSelector = this.paymentDetailsList.locator("img[title='Bancontact card']");
        this.bancontactCardWrapper = this.bancontactCardSelector.locator("..");
        this.storedCardSelector = this.paymentDetailsList.locator("img[alt='Stored Payment Methods']");
        this.storedCardWrapper = this.storedCardSelector.locator("..");

        // Checkout Summary
        this.checkoutSummaryContainer = page.locator(".checkout-aside-container");

        // Submit Order button
        this.submitOrderButton = page.locator("#confirmFormSubmit");

        // Error message
        this.errorMessageContainer = page.locator(".alert-content-container");

    }

    // Redirect in case of an error

    async waitForRedirection() {

        await this.page.waitForNavigation({
            url: /ERROR/,
            timeout: 15000,
        });
    }

    get errorMessage() {
        return this.errorMessageContainer.innerText();
    }

    // General actions

    async acceptTermsAndConditions() {
        await this.termsAndConditionsCheckBox.click({ position: { x: 1, y: 1 } });
    }

    async submitOrder() {
        await this.submitOrderButton.click();
    }

    async loadAllPaymentDetails() {
        if (await this.showMoreButton.isVisible()) {
            await this.showMoreButton.click();
            await this.collapsedPaymentMethods.waitFor({
                state: "visible",
                timeout: 5000,
              })
        }

    }

    async scrollToCheckoutSummary() {
        await this.checkoutSummaryContainer.scrollIntoViewIfNeeded();
    }

    // Payment Method specific actions
    async selectCreditCard() {
        await this.getPaymentMethodReady(this.cardSelector);
        return new CreditCardComponents(this.page);
    }

    async selectPayPal() {
        await this.getPaymentMethodReady(this.payPalSelector);
        return new PayPalComponents(this.page);

    }

    async selectIdeal(){
        await this.getPaymentMethodReady(this.idealSelector);
        return new IDealComponents(this.idealWrapper);
    }

    async selectClearPay(){
        await this.getPaymentMethodReady(this.clearPaySelector);
    }

    async selectKlarnaPayNow(){
        await this.getPaymentMethodReady(this.klarnaPayNowSelector);
    }

    async selectKlarnaPayLater(){
        await this.getPaymentMethodReady(this.klarnaPayLaterSelector);
    }

    async selectKlarnaPayAccount(){
        await this.getPaymentMethodReady(this.klarnaPayAccountSelector);
    }

    async selectSepaDirectDebit(){
        await this.getPaymentMethodReady(this.sepaDirectDebitSelector);
        return new SepaDirectDebitComponents(this.sepaDirectDebitWrapper);
    }

    async selectMultiBanco(){
        await this.getPaymentMethodReady(this.multiBancoSelector);
    }

    async selectOney(){
        await this.getPaymentMethodReady(this.oneySelector);
        return new OneyComponents(this.oneyWrapper);
    }

    async selectBancontactCard(){
        await this.getPaymentMethodReady(this.bancontactCardSelector);
        return new BancontactCardComponents(this.bancontactCardWrapper);
    }

    async selectStoredCard(){
        await this.getPaymentMethodReady(this.storedCardSelector);
        return new CreditCardComponents(this.storedCardWrapper);
    }

    async getPaymentMethodReady(locator) {
        await locator.click();
        await this.page.waitForLoadState("networkidle", { timeout: 10000 });
    }

}
