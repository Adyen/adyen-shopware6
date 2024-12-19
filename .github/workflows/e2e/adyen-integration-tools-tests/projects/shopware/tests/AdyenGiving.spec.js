import { test } from "@playwright/test";
import { ThreeDS2PaymentPage } from "../../common/redirect/ThreeDS2PaymentPage.js";
import PaymentResources from "../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment
} from "../helpers/ScenarioHelper.js";
import { makeCreditCardPayment } from "../helpers/PaymentHelper.js";
import { AdyenGivingComponents } from "../../common/checkoutComponents/AdyenGivingComponents.js";

const paymentResources = new PaymentResources();
const users = paymentResources.guestUser;

test.describe.parallel("Adyen Giving payments", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
        await proceedToPaymentAs(page, users.dutch);
        await doPrePaymentChecks(page);
    });

    test("with 3Ds2 should succeed", async ({ page }) => {

        await makeCreditCardPayment(
            page,
            users.regular,
            paymentResources.masterCard3DS2,
            paymentResources.expDate,
            paymentResources.cvc
        );

        await new ThreeDS2PaymentPage(page).validate3DS2(
            paymentResources.threeDSCorrectPassword
        );

        await verifySuccessfulPayment(page);
        const donationSection = new AdyenGivingComponents(page);
        await donationSection.makeDonation("least");
        await donationSection.verifySuccessfulDonationMessage();
    });
});


