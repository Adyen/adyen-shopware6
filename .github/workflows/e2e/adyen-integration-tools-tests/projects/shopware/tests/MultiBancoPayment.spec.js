import { test } from "@playwright/test";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment,
    verifyVoucherCouponGeneration
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";

const paymentResources = new PaymentResources();
const users = paymentResources.guestUser;

test.describe.parallel("Payment via MultiBanco", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, users.portuguese);
        await doPrePaymentChecks(page);
    });

    test.skip("should succeed", async ({ page }) => {
        const paymentDetailPage = new PaymentDetailsPage(page);
        await paymentDetailPage.selectMultiBanco();
        await paymentDetailPage.submitOrder();
        
        await verifySuccessfulPayment(page);
        await verifyVoucherCouponGeneration(page);
    });

});