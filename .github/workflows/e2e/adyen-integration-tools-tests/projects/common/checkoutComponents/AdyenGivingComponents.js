import { expect } from "@playwright/test";
export class AdyenGivingComponents {
  constructor(page) {
    this.page = page;

    this.adyenGivingContainer = page.locator(".adyen-checkout__adyen-giving");
    this.adyenGivingActionsContainer = this.adyenGivingContainer.locator(
      ".adyen-checkout__adyen-giving-actions"
    );
    this.actionButtonsContainer = this.adyenGivingActionsContainer.locator(
      ".adyen-checkout__amounts"
    );

    this.leastAmountButton = this.actionButtonsContainer
      .locator(".adyen-checkout__button")
      .nth(0);
    this.midAmountButton = this.actionButtonsContainer
      .locator(".adyen-checkout__button")
      .nth(1);
    this.mostAmountButton = this.actionButtonsContainer
      .locator(".adyen-checkout__button")
      .nth(2);

    this.donateButton = this.adyenGivingActionsContainer.locator(
      ".adyen-checkout__button--donate"
    );
    this.declinelButton = this.adyenGivingActionsContainer.locator(
      ".adyen-checkout__button--decline"
    );

    this.DonationMessage = this.adyenGivingContainer.locator(
      ".adyen-checkout__status__text"
    );
  }

  async makeDonation(amount = "least") {
    switch (amount) {
      case "least":
        await this.leastAmountButton.click();
        break;
      case "mid":
        await this.midAmountButton.click();
        break;
      case "most":
        await this.mostAmountButton.click();
        break;
    }
    await this.donateButton.click();
  }

  async declineDonation() {
    await this.declinelButton.click();
  }

  async verifySuccessfulDonationMessage() {
    await expect(this.DonationMessage).toHaveText("Thanks for your support!");
  }
}
