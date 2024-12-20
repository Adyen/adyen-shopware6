
import { BasePage } from "./Base.page.js";

export class ProductDetailPage extends BasePage {
    constructor(page) {
        super(page);
        this.page = page;


        this.productDetailContainer = page.locator(".product-detail-buy");

        this.addToCartButton = this.productDetailContainer.locator(".btn-buy");
        this.increaseProductQuantityButton = this.productDetailContainer.locator(".js-btn-plus").first();

    }

    async changeProductQuantity(quantity) {
        while(quantity > 1){
            await this.increaseProductQuantityButton.click();
            quantity--;
        }
    }

    async clickAddToCart() {
        await this.addToCartButton.click();
    }

    async addItemToCart(itemURL, count = 1) {
        await this.page.goto(`/${itemURL}`);
        if (count > 1) {
            await this.changeProductQuantity(count);
        }
        await this.clickAddToCart();
    }

}