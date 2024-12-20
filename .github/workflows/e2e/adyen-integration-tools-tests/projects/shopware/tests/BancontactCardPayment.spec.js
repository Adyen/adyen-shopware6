import { test } from "@playwright/test";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifyFailedPayment,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";
import { ThreeDS2PaymentPage } from "../../common/redirect/ThreeDS2PaymentPage.js";

const paymentResources = new PaymentResources();
const user = paymentResources.guestUser.belgian;
const bancontactCard = paymentResources.bcmc.be;

test.describe.parallel("Payment via credit card", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, user);
        await doPrePaymentChecks(page);
        const paymentDetailPage = new PaymentDetailsPage(page);
        const bancontactCardSection = await paymentDetailPage.selectBancontactCard();
        
        await bancontactCardSection.fillCardNumber(bancontactCard.cardNumber);
        await bancontactCardSection.fillExpDate(bancontactCard.expDate);

        await paymentDetailPage.submitOrder();
    });

    test("with 3Ds2 should succeed", async ({ page }) => {
        await new ThreeDS2PaymentPage(page).validate3DS2(
            paymentResources.threeDSCorrectPassword
        );

        await verifySuccessfulPayment(page);
    });

    test("with wrong 3Ds2 credentials should fail", async ({ page }) => {
        await new ThreeDS2PaymentPage(page).validate3DS2(
            paymentResources.threeDSWrongPassword
        );

        await verifyFailedPayment(page);
    });
});
