import { test } from "@playwright/test";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifyFailedPayment,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { makeIDeal2Payment } from "../helpers/PaymentHelper.js";

const paymentResources = new PaymentResources();
const users = paymentResources.guestUser;

test.describe.parallel("Payment via iDeal", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, users.dutch);
        await doPrePaymentChecks(page);
    });

    test.skip("should succeed via Test Issuer", async ({ page }) => {
        await makeIDeal2Payment(page, paymentResources.ideal2.issuer, true);
        await verifySuccessfulPayment(page, true);
    });

    test.skip("should fail via Failing Test Issuer", async ({ page }) => {
        await makeIDeal2Payment(page, paymentResources.ideal2.issuer, false);
        await verifyFailedPayment(page, true);
    });

});