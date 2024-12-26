export class IDealComponents {
  constructor(page) {
    this.page = page;

    this.iDealDropDown = page.locator(
      ".adyen-checkout__dropdown__button"
    );
  }

  /** @deprecated on Ideal 2.0 */
  iDealDropDownSelectorGenerator(issuerName) {
    return this.page.locator(
      `.adyen-checkout__dropdown__list li [alt='${issuerName}']`
    );
  }

  /** @deprecated on Ideal 2.0 */
  async selectIdealIssuer(issuerName) {
    await this.iDealDropDown.click();
    await this.iDealDropDownSelectorGenerator(issuerName).click();
  }

  /** @deprecated on Ideal 2.0 */
  async selectRefusedIdealIssuer() {
    await this.iDealDropDown.click();
    await this.iDealDropDownSelectorGenerator("Test Issuer Refused").click();
  }
}
