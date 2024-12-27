
export class SPRBasePage {
    constructor(page) {
        this.page = page;

        // Header section
        this.header = page.locator(".header-minimal");
        this.headerLogo = this.header.locator(".header-logo-main");

        this.backToShopButton = this.header.locator(".header-minimal-back-to-shop-button");

    }

    // General actions
    async navigateBackToShop() {
        await this.backToShopButton.click();
    }
}