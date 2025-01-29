import { test } from "@playwright/test";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";
import { OneyPaymentPage } from "../../common/redirect/OneyPaymentPage.js";

const frenchUser = new PaymentResources().guestUser.oney.approved.fr;

test.describe.parallel("Payment via Oney", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page, 8);
        await proceedToPaymentAs(page, frenchUser);
        await doPrePaymentChecks(page);
    });

    test.skip("should succeed", async ({ page }) => {
        await payViaOney(page);
    });
});

export async function payViaOney(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    const oneyPaymentSection = await paymentDetailPage.selectOney();
    
    await oneyPaymentSection.completeOneyForm(frenchUser)
    await paymentDetailPage.submitOrder();

    await new OneyPaymentPage(page).continueOneyPayment();
}
