export default class KlarnaPaymentPage {
  constructor(page) {
    this.page = page;

    this.phoneNumberVerificationDialog = page.getByTestId('kaf-root');
    this.genericInputField = page.getByTestId('kaf-field');
    this.genericButton = page.getByTestId('kaf-button');
    this.smsVerificationDialog = page.locator('#otp_field');
    this.closeButton = page.getByLabel('Close');
    this.confirmAndPayButton = page.getByTestId('confirm-and-pay');

    this.offersSelectorDialog = page.locator('#offers-selector-dialog');
    this.payInFullRadioOption = this.offersSelectorDialog
      .getByRole('radio', { name: /^pay in full/i })
      .first();
    this.offersSelectorContinueButton = page.getByTestId(
      'offers-selector-continue-button'
    );

    this.payNowSelectorDialog = page.locator(
      '#offers-selector-pay-now-selector-dialog'
    );
    this.cardRadioOption = this.payNowSelectorDialog
      .getByRole('radio', { name: /^card/i })
      .first();
    this.payNowSelectorContinueButton = page.getByTestId(
      'offers-selector-pay-now-selector-continue-button'
    );

    this.threeDsSubmitButton = page
      .getByRole('dialog')
      .filter({ has: page.locator('iframe') })
      .frameLocator('iframe')
      .getByRole('button', { name: /^submit$/i });
  }

  async makeKlarnaPayment(
    phoneNumber,
    paynow = false,
    threeDsChallenge = paynow
  ) {
    await this.waitForKlarnaLoad();
    await this.phoneNumberVerificationDialog.waitFor({ state: 'attached' });
    await this.genericInputField.click();
    await this.genericInputField.fill(phoneNumber);
    await this.genericButton.click();
    await this.smsVerificationDialog.waitFor({ state: 'visible' });
    await this.genericInputField.click();
    await this.genericInputField.fill('111111');

    if (paynow) {
      await this.offersSelectorDialog.waitFor({ state: 'visible' });
      await this.payInFullRadioOption.click();
      await this.offersSelectorContinueButton.click();
      await this.payNowSelectorDialog.waitFor({ state: 'visible' });
      await this.cardRadioOption.click();
      await this.payNowSelectorContinueButton.click();
    }

    await this.confirmAndPayButton.waitFor({ state: 'visible' });
    await this.confirmAndPayButton.click();

    if (threeDsChallenge) {
      await this.threeDsSubmitButton.click();
    }
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
