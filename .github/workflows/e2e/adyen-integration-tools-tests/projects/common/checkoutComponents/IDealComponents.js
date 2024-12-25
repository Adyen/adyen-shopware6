export class IDealComponents {
  constructor(page) {
    this.page = page;

    this.iDealDropDown = page.locator(
      "#payment_form_adyen_hpp_ideal .adyen-checkout__dropdown__button"
    );
  }

  iDealDropDownSelectorGenerator(issuerName) {
    return this.page.locator(
      `#payment_form_adyen_hpp_ideal .adyen-checkout__dropdown__list li [alt='${issuerName}']`
    );
  }

  async selectIdealIssuer(issuerName) {
    await this.iDealDropDown.click();
    await this.iDealDropDownSelectorGenerator(issuerName).click();
  }

  async selectRefusedIdealIssuer() {
    await this.iDealDropDown.click();
    await this.iDealDropDownSelectorGenerator("Test Issuer Refused").click();
  }
}
