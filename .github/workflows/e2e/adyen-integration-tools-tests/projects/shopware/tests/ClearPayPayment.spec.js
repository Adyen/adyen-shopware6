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

const italianUser = new PaymentResources().guestUser.clearPay.approved.it;

test.describe.parallel("Payment via ClearPay", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, italianUser);
        await doPrePaymentChecks(page);
    });

    // Requires specific locations (VPN)
    test.skip("should succeed", async ({ page }) => {

        await payViaClearPay(page);
        await verifySuccessfulPayment(page);

    });
});

export async function payViaClearPay(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectClearPay();
    await paymentDetailPage.submitOrder();

    await new ClearPayPaymentPage(page).continueClearPayPayment();
}
