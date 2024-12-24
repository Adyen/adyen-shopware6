import { test } from "@playwright/test";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";
import { PayPalPaymentPage } from "../../common/redirect/PayPalPaymentPage.js";

const paymentResources = new PaymentResources();
const users = paymentResources.guestUser;

test.describe("Payment via PayPal", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, users.regular);
        await doPrePaymentChecks(page);
    });

    test("should succeed", async ({ page }) => {

        await payViaPayPal(
            page,
            paymentResources.payPalUserName,
            paymentResources.payPalPassword
          );

        await verifySuccessfulPayment(page);

    });

});

async function payViaPayPal(page, username, password) {
    const paymentDetailPage = new PaymentDetailsPage(page);
    const payPalSection = await paymentDetailPage.selectPayPal();

    await page.waitForLoadState("networkidle", { timeout: 10000 });
    await paymentDetailPage.scrollToCheckoutSummary();

    const [popup] = await Promise.all([
      page.waitForEvent("popup"),
      payPalSection.proceedToPayPal(),
    ]);

    await new PayPalPaymentPage(popup).doLoginMakePayPalPayment(username, password);
}
