export class OneyComponents {
  constructor(page) {
    this.activePaymentMethodSection = page;

    this.maleGenderRadioButton = this.activePaymentMethodSection
      .locator(".adyen-checkout__radio_group__input-wrapper")
      .nth(0);
    this.birthdayInput = this.activePaymentMethodSection.locator(
      ".adyen-checkout__input--dateOfBirth"
    );
    this.telephoneNumberInput = this.activePaymentMethodSection.locator(
      ".adyen-checkout__input--telephoneNumber");
  }

  async completeOneyForm(user) {
    await this.maleGenderRadioButton.click();
    await this.birthdayInput.type(user.dateOfBirth);
    
    await this.telephoneNumberInput.fill("");
    await this.telephoneNumberInput.type(user.phoneNumber);
  }
}
