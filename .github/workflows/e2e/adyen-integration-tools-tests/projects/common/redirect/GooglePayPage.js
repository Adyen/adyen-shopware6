import { expect } from "@playwright/test";

export class GooglePayPage {
    constructor(page) {
      this.page = page;
      
      this.loginHeader = page.locator("#headingText")
      this.emailInput = page.locator("input[type='email']");
      this.nextButton = page.getByRole('button', { name: 'Next' });
  
      this.passwordInput = page.locator("input[name='Passwd']");
  
      this.paymentIframe = page.frameLocator("iframe[allow='camera']")
      this.payButton = this.paymentIframe.locator(".jfk-button").first();
    }
  
    async assertNavigation(){
      await this.loginHeader.waitFor({
        state: "visible",
        timeout: 10000,
      });
      await expect(this.emailInput).toBeVisible();
    }

    async fillUsername(username) {
      await this.loginHeader.waitFor({
        state: "visible",
        timeout: 10000,
      });
      await this.emailInput.click();
      await this.emailInput.type(username);
    }
  
    async fillPassword(password) {
      await this.passwordInput.waitFor({
        state: "visible",
        timeout: 10000,
      });
      await this.passwordInput.click();
      await this.passwordInput.type(password);
    }
  
    async clickNext(){
      await this.nextButton.waitFor({
        state: "visible",
        timeout: 10000,
      });
      await this.nextButton.click();
    }
  
    async clickPay(){
      await this.payButton.waitFor({
        state: "visible",
        timeout: 10000,
      });
      await this.payButton.click();
    }
  
    async fillGoogleCredentials(username, password){
      await this.page.waitForLoadState();
  
      await this.fillUsername(username);
      await this.clickNext();
  
      await this.fillPassword(password);
      await this.clickNext();
    }
  
    async pay(){
      await this.clickPay();
    }
  }
