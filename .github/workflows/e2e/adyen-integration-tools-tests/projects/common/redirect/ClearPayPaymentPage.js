import PaymentResources from "../../data/PaymentResources.js";

export class ClearPayPaymentPage {
  constructor(page) {
    this.page = page;

    this.numberEmailInput = page.locator("input[data-testid='login-identity-input']");
    this.loginButton = page.locator("button[data-testid='login-identity-button']");
    
    this.passwordInput = page.locator("input[data-testid='login-password-input']");
    this.continueButton = page.locator("button[data-testid='login-password-button']");

    this.confirmButton = page.locator("button[data-testid='summary-button']");
    
    this.typeDelay = 50;
  }

  // Also used for Afterpay: it takes the email from the order, so a user without phoneNumber skips that step
  async continueClearPayPayment(user = new PaymentResources().guestUser.clearPay.approved.it) {
    await this.page.waitForLoadState();
    
    if (user.phoneNumber) {
      await this.numberEmailInput.waitFor({
        state: "visible",
        timeout: 10000,
      });
      await this.numberEmailInput.click();
      await this.numberEmailInput.fill("");
      await this.numberEmailInput.type(user.phoneNumber, { delay: this.typeDelay });
      await this.loginButton.click();
    }

    await this.passwordInput.waitFor({
      state: "visible",
      timeout: 10000,
    });
    await this.passwordInput.click();
    await this.passwordInput.fill("");
    await this.passwordInput.type(user.password, { delay:this.typeDelay });
    await this.continueButton.click();

    await this.confirmButton.waitFor({
      state: "visible",
      timeout: 10000,
    });
    await this.confirmButton.click();
  }
}
