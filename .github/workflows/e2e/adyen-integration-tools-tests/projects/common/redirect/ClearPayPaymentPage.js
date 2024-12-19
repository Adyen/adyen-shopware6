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

  async continueClearPayPayment() {
    const italianUser =  new PaymentResources().guestUser.clearPay.approved.it

    await this.page.waitForLoadState();
    
    await this.numberEmailInput.waitFor({
      state: "visible",
      timeout: 10000,
    });
    await this.numberEmailInput.click();
    await this.numberEmailInput.fill("");
    await this.numberEmailInput.type(italianUser.phoneNumber, { delay: this.typeDelay });
    await this.loginButton.click();

    await this.passwordInput.waitFor({
      state: "visible",
      timeout: 10000,
    });
    await this.passwordInput.click();
    await this.passwordInput.fill("");
    await this.passwordInput.type(italianUser.password, { delay:this.typeDelay });
    await this.continueButton.click();

    await this.confirmButton.waitFor({
      state: "visible",
      timeout: 10000,
    });
    await this.confirmButton.click();
  }
}
