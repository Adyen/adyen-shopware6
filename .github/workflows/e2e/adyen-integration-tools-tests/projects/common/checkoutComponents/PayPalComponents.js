export class PayPalComponents {
  constructor(page) {
    this.page = page;

    this.payPalButton = page
      .frameLocator("iframe[title='PayPal']").last()
      .locator(".paypal-button").first();
  }

  async proceedToPayPal() {
    // The iframe which contains PayPal button may require extra time to load
    await new Promise(r => setTimeout(r, 500));
    await this.payPalButton.scrollIntoViewIfNeeded();
    await this.payPalButton.hover();
    await this.payPalButton.click();
  }
}
