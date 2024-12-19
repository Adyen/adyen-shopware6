export class AmazonPayPaymentPage {
  constructor(page) {
    this.page = page;

    this.emailInput = this.page.locator("#ap_email");
    this.passwordInput = this.page.locator("#ap_password");
    this.loginButton = this.page.locator("#signInSubmit");
    this.payNowButton = this.page.locator('#continue-button');
    this.cancelPayment = this.page.locator('#return_back_to_merchant_link');

    this.paymentMethodItem = this.page.locator(".buyer-list-item");

    this.changePaymentButton = this.page.locator("#change-payment-button");
    this.confirmPaymentChangeButton = this.page.locator("#a-autoid-3");
    this.amazonCaptcha = this.page.locator('//img[contains(@alt,"captcha")]')
  }

  async doLogin(amazonCredentials){
    await this.emailInput.click();
    await this.emailInput.type(amazonCredentials.username);
    await this.passwordInput.click();
    await this.passwordInput.type(amazonCredentials.password);
    await this.loginButton.click();

    await this.page.waitForLoadState();
  }

  async selectPaymentMethod(testCase) {
    await this.changePaymentButton.click();
    await this.page.waitForLoadState();

    switch (testCase) {
      case 'declined':
        await this.paymentMethodItem.nth(5).click();
        break;
      case '3ds2':
        await this.paymentMethodItem.nth(4).click();
        break;
    }

    await this.confirmPaymentChangeButton.click();
    await this.page.waitForLoadState();
  }

  async completePayment() {
    await this.payNowButton.click();
  }

  async cancelTransaction() {
    await this.cancelPayment.click();
  }

  async isCaptchaMounted() {
    return !!(await this.amazonCaptcha.isVisible({timeout: 5000}));
  }
}
