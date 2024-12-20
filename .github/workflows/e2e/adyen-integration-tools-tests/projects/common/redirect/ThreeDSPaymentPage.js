export class ThreeDSPaymentPage {
  constructor(page) {
    this.page = page;
    this.threeDSUsernameInput = page.locator("#username");
    this.threeDSPasswordInput = page.locator("#password");
    this.threeDSSubmit = page.locator('input[type="submit"]');
  }

  async validate3DS(username, password) {
    await this.fillThreeDSCredentialsAndSubmit(username, password);
  }

  async fillThreeDSCredentialsAndSubmit(username, password) {
    await this.threeDSUsernameInput.type(username);
    await this.threeDSPasswordInput.type(password);
    await this.threeDSSubmit.click();
  }
}
