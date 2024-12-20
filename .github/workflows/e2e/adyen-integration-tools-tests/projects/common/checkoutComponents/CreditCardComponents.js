export class CreditCardComponents {
  constructor(page) {
    this.page = page;

    this.holderNameInput = page.locator(
      ".adyen-checkout__card__holderName input"
    );

    this.cardNumberInput = page
      .frameLocator(".adyen-checkout__card__cardNumber__input iframe")
      .locator(".input-field");

    this.expDateInput = page
      .frameLocator(".adyen-checkout__card__exp-date__input iframe")
      .locator(".input-field");

    this.cvcInput = page
      .frameLocator(".adyen-checkout__card__cvc__input iframe")
      .locator(".input-field");

    this.typeDelay = 50;
  }

  async fillHolderName(holderName) {
    await this.holderNameInput.scrollIntoViewIfNeeded();
    await this.holderNameInput.click();
    await this.holderNameInput.type(holderName);
  }
  async fillCardNumber(cardNumber) {
    await this.cardNumberInput.scrollIntoViewIfNeeded();
    await this.cardNumberInput.click();
    await this.cardNumberInput.type(cardNumber, { delay: this.typeDelay });
  }
  async fillExpDate(expDate) {
    await this.expDateInput.scrollIntoViewIfNeeded();
    await this.expDateInput.click();
    await this.expDateInput.type(expDate, { delay: this.typeDelay });
  }
  async fillCVC(CVC) {
    await this.cvcInput.scrollIntoViewIfNeeded();
    await this.cvcInput.click();
    await this.cvcInput.type(CVC, { delay: this.typeDelay });
  }

  async fillCreditCardInfo(
    cardHolderName,
    cardHolderLastName,
    cardNumber,
    cardExpirationDate,
    cardCVC = undefined
  ) {
    await this.fillCardNumber(cardNumber);
    await this.fillExpDate(cardExpirationDate);
    if (cardCVC !== undefined ) {
      await this.fillCVC(cardCVC);
    }
    await this.fillHolderName(cardHolderName);
    await this.fillHolderName(` ${cardHolderLastName}`);
  }
}
