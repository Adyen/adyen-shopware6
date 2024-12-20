import { expect } from "@playwright/test";
import { PaymentDetailsPage } from "../pageObjects/plugin/PaymentDetails.page.js";
import { ProductDetailPage } from "../pageObjects/plugin/ProductDetail.page.js";
import { ResultPage } from "../pageObjects/plugin/Result.page.js";
import { ShippingDetailsPage } from "../pageObjects/plugin/ShippingDetails.page.js";

export async function goToShippingWithFullCart(page, quantity) {
  const productDetailPage = new ProductDetailPage(page);

  await productDetailPage.addItemToCart("Main-product-free-shipping-with-highlighting/SWDEMO10006",
    quantity);
  expect.soft(await productDetailPage.alertMessage.textContent()).toContain("added to your shopping cart");
  await productDetailPage.clickProceedToCheckout();
}

export async function proceedToPaymentAs(page, user, saveUser = false) {
  const shippingDetailsPage = new ShippingDetailsPage(page);
  await shippingDetailsPage.fillShippingDetails(user, saveUser);
  await shippingDetailsPage.clickContinue();
}

export async function doPrePaymentChecks(page, acceptTerms = true) {
  const paymentDetailsPage = new PaymentDetailsPage(page);
  await page.waitForLoadState("domcontentloaded", { timeout: 10000 });
  if(acceptTerms) {
    await paymentDetailsPage.acceptTermsAndConditions();
  }
  await paymentDetailsPage.loadAllPaymentDetails();
}

export async function verifySuccessfulPayment(page, redirect = true, timeout) {
  const successfulResultPage = new ResultPage(page);
  if (redirect) {
    await successfulResultPage.waitForRedirection({ timeout:timeout });
  }
  expect(await successfulResultPage.titleText()).toContain(
    "Thank you for your order with"
  );
}

export async function verifyFailedPayment(page, redirect = true) {
  const failedResultPage = new PaymentDetailsPage(page);
  if (redirect) {
    await failedResultPage.waitForRedirection();
  }
  expect(await failedResultPage.errorMessage).toContain(
    "please change the payment method or try again"
  );
}

export async function verifyVoucherCouponGeneration(page){
  const successfulResultPage = new ResultPage(page);
  await expect(successfulResultPage.voucherCodeContainer).toBeVisible();
}

