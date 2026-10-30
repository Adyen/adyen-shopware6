import { test } from "@playwright/test";
import { ClearPayPaymentPage } from "../../common/redirect/ClearPayPaymentPage.js";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";

const australianUser = new PaymentResources().guestUser.afterpayTouch.approved.au;

test.describe.parallel("Payment via Afterpay", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, australianUser);
        await doPrePaymentChecks(page);
    });

    // Requires specific locations (VPN), an AUD sales channel and the Afterpay sandbox shopper
    test.skip("should succeed", async ({ page }) => {

        await payViaAfterpay(page);
        await verifySuccessfulPayment(page);

    });
});

export async function payViaAfterpay(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectAfterpay();
    await paymentDetailPage.submitOrder();

    await new ClearPayPaymentPage(page).continueClearPayPayment(australianUser);
}
