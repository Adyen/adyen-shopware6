import { expect, test } from "@playwright/test";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";
import { ShippingDetailsPage } from "../pageObjects/plugin/ShippingDetails.page.js";

const paymentResources = new PaymentResources();
const dutchUser = paymentResources.guestUser.dutch;
const britishUser = paymentResources.guestUser.regular;

test.describe("Payment methods", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, dutchUser);
        await doPrePaymentChecks(page);
    });

    test("should be updated when the billing address is changed", async ({ page }) => {
        const paymentDetailPage = new PaymentDetailsPage(page);
        const shippingDetailPage = new ShippingDetailsPage(page);

        await expect(await paymentDetailPage.idealSelector).toBeVisible();
        await shippingDetailPage.changeBillingAddress(britishUser);

        await expect(await shippingDetailPage.alertMessage).toContainText("Address has been saved");
        await doPrePaymentChecks(page);
        await expect(await paymentDetailPage.idealSelector).not.toBeVisible();
    });
});
