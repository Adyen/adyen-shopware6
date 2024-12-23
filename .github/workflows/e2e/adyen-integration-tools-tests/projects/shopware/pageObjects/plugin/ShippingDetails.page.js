import { SPRBasePage } from "./SPRBase.page.js";

export class ShippingDetailsPage extends SPRBasePage {
    constructor(page) {
        super(page);
        this.page = page;

        //Alert message
        this.alertMessage = page.locator(".alert-content");
        
        //Change Billing Address Form
        this.changeBillingAddressButton = page.locator("text=Change billing address");
        
        this.currentAddressModal = page.locator(".address-editor-modal");
        this.editAddressButton = this.currentAddressModal.locator("text=Edit address").first();

        this.editAddressEditorWrapper = page.locator(".address-editor-create-address-wrapper").first();
        
        this.editSalutationDropDown = this.editAddressEditorWrapper.locator("#billing-addresspersonalSalutation");
        this.editFirstNameField = this.editAddressEditorWrapper.locator("#billing-addresspersonalFirstName");
        this.editLastNameField = this.editAddressEditorWrapper.locator("#billing-addresspersonalLastName");
        this.editAddressField = this.editAddressEditorWrapper.locator("#billing-edit-addressAddressStreet");
        this.editPostCodeField = this.editAddressEditorWrapper.locator("#billing-edit-addressAddressZipcode");
        this.editCityField = this.editAddressEditorWrapper.locator("#billing-edit-addressAddressCity");
        this.editCountrySelectDropdown = this.editAddressEditorWrapper.locator("#billing-edit-addressAddressCountry");
        this.editStateSelectDropDown = this.editAddressEditorWrapper.locator("#billing-edit-addressAddressCountryState");
        
        this.editSaveAddressButton = this.editAddressEditorWrapper.locator("button[type='submit']");
        
        // Shipping details form
        this.shippingFormContainer = page.locator(".register-form");

        this.salutationDropDown = this.shippingFormContainer.locator("#personalSalutation");
        this.firstNameField = this.shippingFormContainer.locator("#personalFirstName");
        this.lastNameField = this.shippingFormContainer.locator("#personalLastName");
        this.createCustomerAccountCheckBox = this.shippingFormContainer.locator("label[for='personalGuest']");
        this.emailField = this.shippingFormContainer.locator("#personalMail");
        this.passwordField = this.shippingFormContainer.locator("#personalPassword");

        this.addressField = this.shippingFormContainer.locator("#billingAddressAddressStreet");
        this.postCodeField = this.shippingFormContainer.locator("#billingAddressAddressZipcode");
        this.cityField = this.shippingFormContainer.locator("#billingAddressAddressCity");
        this.countrySelectDropdown = this.shippingFormContainer.locator("#billingAddressAddressCountry");
        this.stateSelectDropDown = this.shippingFormContainer.locator("#billingAddressAddressCountryState");

        //Continue button
        this.continueButton = page.locator(".register-submit button.btn-primary");

    }

    // Shipping details actions
    async fillShippingDetails(user, saveUser) {
        await this.salutationDropDown.selectOption({ index: 1 });
        await this.firstNameField.fill(user.firstName);
        await this.lastNameField.fill(user.lastName);
        
        await this.emailField.fill(user.email);
        if (saveUser){
            await this.createCustomerAccountCheckBox.click();
            await this.passwordField.type(user.password);
        }

        await this.addressField.fill(user.street);
        await this.postCodeField.fill(user.postCode);
        await this.cityField.fill(user.city);

        await this.countrySelectDropdown.scrollIntoViewIfNeeded();

        const dropdownValue = await this.countrySelectDropdown.locator(`//option[contains(text(),'${user.countryName}')]`).getAttribute("value");
        await this.countrySelectDropdown.selectOption(dropdownValue);

        if (await this.stateSelectDropDown.isVisible()) {
            await this.stateSelectDropDown.selectOption({ index: 2 });
        }
    }

    async clickContinue() {
        await this.continueButton.click();
    }

    async changeBillingAddress(user) {
        await this.changeBillingAddressButton.click();
        await this.currentAddressModal.waitFor({ state: "visible", timeout: 10000});
        await this.editAddressButton.click();
        await this.editSaveAddressButton.waitFor({ state: "visible", timeout: 10000});

        await this.editAddressField.fill(user.firstName);
        await this.editPostCodeField.fill(user.postCode);
        await this.editCityField.fill(user.city);

        const dropdownValue = await this.editCountrySelectDropdown.locator(`//option[contains(text(),'${user.countryName}')]`).getAttribute("value");
        await this.editCountrySelectDropdown.selectOption(dropdownValue);

        if (await this.editStateSelectDropDown.isVisible()) {
            await this.editStateSelectDropDown.selectOption({ index: 2 });
        }

        await this.editSaveAddressButton.click();
        await this.currentAddressModal.waitFor({ state: "detached", timeout: 10000});
    }
    

}
