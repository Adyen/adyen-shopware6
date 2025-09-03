export class BasePage {
    constructor(page) {
        this.page = page;

        // Header section
        this.header = page.locator(".header-main");
        this.headerLogo = this.header.locator(".header-logo-main");

        this.cartButton = this.header.locator("a[aria-label='Shopping cart']");

        this.currencyDropdownButton = this.header.locator(".currenciesDropdown-top-bar");
        this.currencylist = page.locator("div[aria-labelledby='currenciesDropdown-top-bar']");

        // Shopping Cart Sidebar
        this.sideBarContainer = page.locator(".cart-offcanvas");

        this.continueShoppingButton = this.sideBarContainer.locator(".offcanvas-close");
        this.alertMessage = this.sideBarContainer.locator(".alert-content-container").first();
        this.proceedToCheckoutButton = this.sideBarContainer.getByRole('link', { name: 'Go to checkout' });

        this.firstProductContainer = this.sideBarContainer.locator(".cart-item-product").first();
        this.deleteProductButton = this.firstProductContainer.locator(".cart-item-remove-button");
        this.sidebarProductQuantityDropdown = this.firstProductContainer.locator("select[name='quantity']");

    }

    // General actions
    async selectCurrency(currency) {
        await this.currencyDropdownButton.click();
        await this.currencylist.locator(`div[title='${currency}'] label`).click();
    }


    // Sidebar actions
    async triggerShoppingCartSideBar() {
        await this.cartButton.click();
    }

    async changeSidebarProductQuantity(quantity) {
        await this.sidebarProductQuantityDropdown.selectOption(`${quantity}`);
    }

    async closeShoppingCartSideBar() {
        await this.continueShoppingButton.click();
    }

    async clickProceedToCheckout() {
        await this.proceedToCheckoutButton.click();
    }

    async getSidebarAlertMessage() {
        return await this.alertMessage.textContent();
    }


}