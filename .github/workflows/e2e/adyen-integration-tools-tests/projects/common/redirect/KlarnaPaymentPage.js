export default class KlarnaPaymentPage {
  constructor(page) {
    this.page = page;

    this.phoneNumberVerificationDialog = page.getByTestId('kaf-root');
    this.genericInputField = page.getByTestId('kaf-field');
    this.genericButton = page.getByTestId('kaf-button');
    this.smsVerificationDialog = page.locator('#otp_field');
    this.closeButton = page.getByLabel(/^(close|sluiten)$/i);
    this.confirmAndPayButton = page.getByTestId('confirm-and-pay');

    this.offersSelectorDialog = page.locator('#offers-selector-dialog');
    this.payNowOfferOption = this.offersSelectorDialog
      .locator(
        'label[data-fs-offer-type="PAY_NOW"] [role="radio"], ' +
          '#offers-selector__pay-now-group_pay_now__label'
      )
      .first();
    this.offersSelectorContinueButton = page.getByTestId(
      'offers-selector-continue-button'
    );

    this.payNowSelectorDialog = page.locator(
      '#offers-selector-pay-now-selector-dialog'
    );
    this.cardRadioOption = this.payNowSelectorDialog
      .locator(
        'label[data-fs-offer-type*="CARD" i] [role="radio"], ' +
          '[role="radio"][id*="card" i]'
      )
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
      await this.selectRadioIfNeeded(this.payNowOfferOption);
      await this.offersSelectorContinueButton.click();
      await this.payNowSelectorDialog.waitFor({ state: 'visible' });
      await this.selectRadioIfNeeded(this.cardRadioOption);
      await this.payNowSelectorContinueButton.click();
    }

    await this.confirmAndPayButton.waitFor({ state: 'visible' });
    await this.confirmAndPayButton.click();

    if (threeDsChallenge) {
      await this.threeDsSubmitButton.click();
    }
  }

  /**
   * Klarna preselects the offer when only one is available; the radio is a
   * `div[role=radio]` with `aria-checked`. Only click when it exists and is
   * not selected yet, otherwise just continue with the preselected option.
   */
  async selectRadioIfNeeded(option) {
    if ((await option.count()) === 0) {
      return;
    }

    if ((await option.getAttribute('aria-checked')) === 'true') {
      return;
    }

    await option.click();
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
