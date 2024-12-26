import { expect } from "@playwright/test";

export class QRPage {
    constructor(page) {
        this.page = page;
        this.QRCodeWrapper = page.locator(".adyen-checkout__qr-loader");
        this.QRCode = this.QRCodeWrapper.locator("img[alt='Scan QR code']");
    }
  
    async verifySuccessfulQRCode() {
        await this.page.waitForLoadState(
            "load", 
            { timeout: 10000 }
        );
        
        await expect(this.QRCode).toBeVisible({ timeout: 10000 });
    }
}
