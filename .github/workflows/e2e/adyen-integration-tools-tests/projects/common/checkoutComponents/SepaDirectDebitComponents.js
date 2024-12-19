export class SepaDirectDebitComponents {
  constructor(page) {
    this.page = page;

    this.accountHolderNameInput = this.page.locator(
      "input[name='ownerName']"
    );
    this.accountNumberInput = this.page.locator(
      "input[name='ibanNumber']"
    );
  }

  async fillSepaDirectDebitInfo(accountHolderName, accountNumber) {
    await this.accountHolderNameInput.click();
    await this.accountHolderNameInput.fill(accountHolderName);

    await this.accountNumberInput.click();
    await this.accountNumberInput.fill(accountNumber);
  }
}
