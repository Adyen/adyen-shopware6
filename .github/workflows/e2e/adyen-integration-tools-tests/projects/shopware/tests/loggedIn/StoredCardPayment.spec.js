import { test } from "@playwright/test";
import { ThreeDS2PaymentPage } from "../../../common/redirect/ThreeDS2PaymentPage.js";
import PaymentResources from "../../../data/PaymentResources.js";
import {
    doPrePaymentChecks,
    goToShippingWithFullCart,
    proceedToPaymentAs,
    verifySuccessfulPayment
} from "../../helpers/ScenarioHelper.js";
import { makeCreditCardPayment } from "../../helpers/PaymentHelper.js";
import { PaymentDetailsPage } from "../../pageObjects/plugin/PaymentDetails.page.js";

const paymentResources = new PaymentResources();
const user = paymentResources.guestUser.regular;

test.describe.parallel("Payment via stored credit card", () => {
    test.beforeEach(async ({ page }) => {
        await goToShippingWithFullCart(page);
    });

    test("with 3Ds2 should succeed", async ({ page }) => {
        
        user.email = `${Math.floor(Math.random()*1000)}${user.email}`;
        await proceedToPaymentAs(page, user, true);
        await doPrePaymentChecks(page);

        await makeCreditCardPayment(
            page,
            user,
            paymentResources.masterCard3DS2,
            paymentResources.expDate,
            paymentResources.cvc,
            true
        );

        await new ThreeDS2PaymentPage(page).validate3DS2(
            paymentResources.threeDSCorrectPassword
        );

        await verifySuccessfulPayment(page);

        await goToShippingWithFullCart(page);
        await doPrePaymentChecks(page);

        const paymentDetailPage = new PaymentDetailsPage(page);
        const storedCardSection = await paymentDetailPage.selectStoredCard();
        
        await storedCardSection.fillCVC(paymentResources.cvc);
        await paymentDetailPage.submitOrder();

        await new ThreeDS2PaymentPage(page).validate3DS2(
            paymentResources.threeDSCorrectPassword
        );

        await verifySuccessfulPayment(page);

    });
});
