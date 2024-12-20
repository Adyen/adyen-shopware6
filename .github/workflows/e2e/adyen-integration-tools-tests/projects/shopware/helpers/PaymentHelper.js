import { IdealIssuerPage } from "../../common/redirect/IdealIssuerPage.js";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";


export async function makeCreditCardPayment(
    page,
    user,
    creditCardNumber,
    expDate,
    cvc,
    saveCard = false
) {
    const paymentDetailPage = new PaymentDetailsPage(page);
    const creditCardSection = await paymentDetailPage.selectCreditCard();
    await creditCardSection.fillCreditCardInfo(
        user.firstName,
        user.lastName,
        creditCardNumber,
        expDate,
        cvc
    );
    if (saveCard) {
        await page.locator("text=Save for my next payment").click();
    }
    await new PaymentDetailsPage(page).submitOrder();
}

/** @deprecated on Ideal 2.0 use makeIDeal2Payment() instead */
export async function makeIDealPayment(page, issuerName) {
    const paymentDetailPage = new PaymentDetailsPage(page);
    const idealPaymentSection = await paymentDetailPage.selectIdeal();
    
    await idealPaymentSection.selectIdealIssuer(issuerName);
    await paymentDetailPage.scrollToCheckoutSummary();
    await paymentDetailPage.submitOrder();
  
    await page.waitForNavigation();
    await new IdealIssuerPage(page).continuePayment();
}

export async function makeIDeal2Payment(page, bankName, success = true) {
    const paymentDetailPage = new PaymentDetailsPage(page);
    await paymentDetailPage.selectIdeal();

    await paymentDetailPage.scrollToCheckoutSummary();
    await paymentDetailPage.submitOrder();
    await page.waitForNavigation();

    const idealIssuerPage = new IdealIssuerPage(page, bankName);

    await idealIssuerPage.proceedWithSelectedBank();

    if (success) {
        await idealIssuerPage.simulateSuccess();
    } else {
        await idealIssuerPage.simulateFailure();
    }

    await page.waitForLoadState("load", { timeout: 10000 });
}