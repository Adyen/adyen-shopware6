export class AmazonPayComponents {
  constructor(page) {
    this.page = page;

    this.amazonPayContainer = page.locator("#amazonpayContainer");
    this.amazonPayButton = this.amazonPayContainer.getByLabel('Amazon Pay - Use your Amazon Pay Sandbox test account');

  }

  async clickAmazonPayButton() {
    await this.amazonPayButton.click();
  }
}
