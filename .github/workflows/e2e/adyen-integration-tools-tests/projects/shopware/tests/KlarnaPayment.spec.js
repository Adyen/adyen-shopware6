import { test } from "@playwright/test";
import KlarnaPaymentPage from "../../common/redirect/KlarnaPaymentPage.js";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifyFailedPayment,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";

const user = new PaymentResources().guestUser.klarna.approved.nl

test.describe.parallel("Payment via Klarna", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, user);
        await doPrePaymentChecks(page);
    });

    test("Pay Now should succeed via pay now", async ({ page }) => {
        const klarnaPaymentPage = await proceedToKlarnaPayNow(page);
        await klarnaPaymentPage.makeKlarnaPayment(user.phoneNumber, true);
        await verifySuccessfulPayment(page, true, 25000);
    });

    test.skip("Pay Now should fail gracefully when cancelled", async ({ page }) => {
        const klarnaPaymentPage = await proceedToKlarnaPayNow(page);
        await klarnaPaymentPage.cancelKlarnaPayment();
        await verifyFailedPayment(page, false);
    });

    test("Pay Later should succeed", async ({ page }) => {
        const klarnaPaymentPage = await proceedToKlarnaPayLater(page);
        await klarnaPaymentPage.makeKlarnaPayment(user.phoneNumber, false);
        await verifySuccessfulPayment(page);
    });

});

test.describe.parallel("Payment via Klarna", () => {
    test.beforeEach(async ({ page }) => {
        // Seperate flow for Account payments since minimum supported amount is 35 EUR
        await goToShippingWithFullCart(page, 2);
        await proceedToPaymentAs(page, user);
        await doPrePaymentChecks(page);
    });

    test("Pay Klarna Account should succeed", async ({ page }) => {
        const klarnaPaymentPage = await proceedToKlarnaPayAccount(page);
        await klarnaPaymentPage.makeKlarnaPayment(user.phoneNumber, false);
        await verifySuccessfulPayment(page);
    });

});

async function proceedToKlarnaPayNow(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectKlarnaPayNow();
    await paymentDetailPage.submitOrder();
    return new KlarnaPaymentPage(page);
}

async function proceedToKlarnaPayLater(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectKlarnaPayLater();
    await paymentDetailPage.submitOrder();
    return new KlarnaPaymentPage(page);
}

async function proceedToKlarnaPayAccount(page){
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectKlarnaPayAccount();
    await paymentDetailPage.submitOrder();
    return new KlarnaPaymentPage(page);
}
