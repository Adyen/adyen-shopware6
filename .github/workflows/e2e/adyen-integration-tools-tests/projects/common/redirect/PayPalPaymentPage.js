export class PayPalPaymentPage {
  constructor(page) {
    this.page = page;

    this.changeEmailAddressButton = page.locator(".notYouLink");
    this.emailInput = page.locator("#email");
    this.nextButton = page.locator("#btnNext");
    this.passwordInput = page.locator("#password");
    this.loginButton = page.locator("#btnLogin");
    this.agreeAndPayNowButton = page.locator("#payment-submit-btn");
    this.cancelButton = page.locator("#cancelLink");
    this.changeShippingAddressButton = page.locator("#change-shipping");
    this.shippingAddressDropdown = page.locator("#shippingDropdown");
    this.shippingMethodsDropdown = page.locator("#shippingMethodsDropdown");

    this.loggingInAnimation = page.locator("#app-loader");
    this.cookiesWrapper = page.locator("#ccpaCookieBanner");
    this.cookiesDeclineButton = this.cookiesWrapper.getByRole('button', { name: 'decline' });
  }

  async loginToPayPalAndHandleCookies(username, password) {
    await this.waitForPopupLoad(this.page);

    await this.emailInput.fill(username);
    await this.nextButton.click();
    await this.passwordInput.fill(password);
    await this.loginButton.click();

    await this.waitForAnimation();

    await this.declineCookies();
  }

  async waitForAnimation() {
    await this.loggingInAnimation.waitFor({state: "visible", timeout: 10000});
    await this.loggingInAnimation.waitFor({state: "hidden", timeout: 15000});
  }

  async agreeAndPay() {
    await this.agreeAndPayNowButton.click();
  }

  async doLoginMakePayPalPayment(username, password) {
    await this.loginToPayPalAndHandleCookies(username, password);

    await this.agreeAndPay();
  }

  async declineCookies() {
    if (await this.cookiesDeclineButton.isVisible()) {
      await this.cookiesDeclineButton.click();
    }
  }

  async cancelAndGoToStore() {
    await this.waitForPopupLoad(this.page);
    await this.cancelButton.click();
  }

  async waitForPopupLoad(page) {
    await page.waitForURL(/.*sandbox.paypal.com*/,
      {
      timeout: 10000,
      waitUntil:"load"
    });
  }

  async changeShippingAddress() {
    await this.changeShippingAddressButton.click();

    const newShippingAddressValue = await this.shippingAddressDropdown.first()
        .getByRole("option")
        .nth(1)
        .getAttribute("value");
    await this.shippingAddressDropdown.first().selectOption(newShippingAddressValue);

    const newShippingMethodValue = await this.shippingMethodsDropdown.first()
        .getByRole("option")
        .nth(1)
        .getAttribute("value");
    await this.shippingMethodsDropdown.first().selectOption(newShippingMethodValue);
  }
}
