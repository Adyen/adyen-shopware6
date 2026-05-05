export default class KlarnaPaymentPage {
  constructor(page) {
    this.page = page;

    this.phoneNumberVerificationDialog = page.getByTestId('kaf-root');
    this.genericInputField = page.getByTestId('kaf-field');
    this.genericButton = page.getByTestId('kaf-button');
    this.smsVerificationDialog = page.locator('#otp_field');
    this.closeButton = page.getByLabel('Close');
    this.confirmAndPayButton = page.getByTestId('confirm-and-pay');

    this.chooseHowToPayDialog = page.getByRole('dialog', {
      name: /choose how to pay/i,
    });
    this.cardRadioOption = page.getByRole('radio', { name: /^card/i });
    this.continueButton = page.getByRole('button', { name: /^continue$/i });
  }

  async makeKlarnaPayment(phoneNumber, paynow = false) {
    await this.waitForKlarnaLoad();
    await this.phoneNumberVerificationDialog.waitFor({ state: 'attached' });
    await this.genericInputField.click();
    await this.genericInputField.fill(phoneNumber);
    await this.genericButton.click();
    await this.smsVerificationDialog.waitFor({ state: 'visible' });
    await this.genericInputField.click();
    await this.genericInputField.fill('111111');

    if (paynow) {
      await this.chooseHowToPayDialog.waitFor({ state: 'visible' });
      await this.cardRadioOption.click();
      await this.continueButton.click();
    }

    await this.confirmAndPayButton.waitFor({ state: 'visible' });
    await this.confirmAndPayButton.click();
  }

  async cancelKlarnaPayment() {
    await this.waitForKlarnaLoad();
    await this.page.waitForLoadState("networkidle", { timeout: 10000 });
    await this.closeButton.click();
    await this.page.waitForURL(/.*account\/order\/edit.*/, { timeout: 30000 });
  }

  async waitForKlarnaLoad() {
    await this.page.waitForURL(/.*playground\.klarna.*/, {
      timeout: 15000,
      waitUntil: "load",
    });
  }
}
