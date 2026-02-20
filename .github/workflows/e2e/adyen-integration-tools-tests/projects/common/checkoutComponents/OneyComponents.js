export class OneyComponents {
    constructor(page) {
        this.activePaymentMethodSection = page;

        this.birthdayInput = this.activePaymentMethodSection.locator(
            ".adyen-checkout__input--dateOfBirth"
        );
        this.telephoneNumberInput = this.activePaymentMethodSection.locator(
            ".adyen-checkout__input--telephoneNumber");
    }

    async completeOneyForm(user) {
        await this.birthdayInput.type(user.dateOfBirth);

        await this.telephoneNumberInput.fill("");
        await this.telephoneNumberInput.type(user.phoneNumber);
    }
}
