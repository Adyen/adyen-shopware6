import PaymentResources from "../../data/PaymentResources.js";
export class OneyPaymentPage {
  constructor(page) {
    this.page = page;

    this.continueWithoutLoggingInButton = page.locator(".primary-button")

    this.genderSelector = page.locator(".civility-selectable").nth(2);
    this.birthDateInput = page.locator("input#birthDate");

    this.birthPlaceInput = page.locator("input#birthCity");
    this.birthPlaceList = page.locator("#cdk-overlay-1 mat-option").first();

    this.birthDepartmentSelector = page.locator("#birthDepartment");

    this.cardHolderNameInput = page.locator("#cardOwner");
    this.cardNumberInput = page.locator("#cardNumber");
    this.cardExpDateInput = page.locator("#cardExpirationDate");
    this.cardCvvInput = page.locator("#cvv");

    this.termsAndConditionsCheckBox = page.locator(
      "label[for='generalTermsAndConditions']"
    );
    this.completePaymentButton = page.locator("button[type='submit']");

    this.oneyAnimationLayer = page.locator(".loader-message");

    this.returnToMerchantSiteButton = page.locator("#successRedirectLink");
  }

  async continueOneyPayment() {
    await this.page.waitForURL(/.*oney*/, { timeout: 15000 });
    /*
    Oney's sandboxes are ever inconsistent and flaky, checking only
    redirection until further notice

    const paymentResources = new PaymentResources();
    const user = paymentResources.guestUser.oney.approved.fr;

    await this.page.waitForLoadState("networkidle", { timeout: 10000 });
    
    await this.continueWithoutLoggingInButton.click();
    await this.genderSelector.click();
    await this.birthPlaceInput.click();
    await this.birthPlaceInput.type(user.city);
    await this.birthPlaceList.click();

    await this.cardHolderNameInput.type(`${user.firstName} ${user.lastName}`);

    await this.cardNumberInput.type(paymentResources.oneyCard);
    await this.cardExpDateInput.type(paymentResources.expDate);
    await this.cardCvvInput.type(paymentResources.cvc);

    await this.termsAndConditionsCheckBox.click();
    await this.completePaymentButton.click();

    await this.waitForOneyAnimation();
    await this.waitForOneySuccessPageLoad();

    await this.returnToMerchantSiteButton.click(); */
  }

  async waitForOneySuccessPageLoad() {
    await this.page.waitForURL(/.*success*/, { timeout: 15000 });
  }

  async waitForOneyAnimation() {
    await this.oneyAnimationLayer.waitFor({ state: "attached", timeout: 5000 });
  }
}
