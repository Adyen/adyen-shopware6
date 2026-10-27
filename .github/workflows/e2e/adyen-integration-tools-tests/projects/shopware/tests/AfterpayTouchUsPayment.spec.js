import { test } from "@playwright/test";
import { AfterpayPaymentPage } from "../../common/redirect/AfterpayPaymentPage.js";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";

const americanUser = new PaymentResources().guestUser.cashAppAfterpay.approved.us;

test.describe.parallel("Payment via Cash App Afterpay", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, americanUser);
        await doPrePaymentChecks(page);
    });

    // Requires specific locations (VPN), a USD sales channel and the Cash App Afterpay sandbox shopper
    test.skip("should succeed", async ({ page }) => {

        await payViaCashAppAfterpay(page);
        await verifySuccessfulPayment(page);

    });
});

export async function payViaCashAppAfterpay(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectCashAppAfterpay();
    await paymentDetailPage.submitOrder();

    await new AfterpayPaymentPage(page).continueAfterpayPayment(americanUser);
}
