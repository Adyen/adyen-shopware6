<?php

namespace Adyen\Shopware\Storefront\Page\Checkout\Confirm;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\PaymentMethodsService;

class AdyenCheckoutConfirmPageLoader extends CheckoutConfirmPageLoader
{
    /**
     * @var CheckoutConfirmPageLoader $checkoutConfirmPageLoader
     */
    private $checkoutConfirmPageLoader;

    /**
     * @var PaymentMethodsService $paymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * CheckoutConfirmPageLoader constructor.
     * @param CheckoutConfirmPageLoader $checkoutConfirmPageLoader
     * @param CartService $cartService
     */
    public function __construct(
        CheckoutConfirmPageLoader $checkoutConfirmPageLoader,
        PaymentMethodsService $paymentMethodsService
    ) {
        $this->checkoutConfirmPageLoader = $checkoutConfirmPageLoader;
        $this->paymentMethodsService = $paymentMethodsService;
    }

    function load(Request $request, SalesChannelContext $salesChannelContext): CheckoutConfirmPage
    {
        //Get original page object and PM list to decorate
        $page = $this->checkoutConfirmPageLoader->load($request, $salesChannelContext);
        $originalPaymentMethods = $page->getPaymentMethods();

        //Adyen /paymentMethods response
        $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);

        foreach ($originalPaymentMethods as $paymentMethodEntity) {
            $pmHandlerIdentifier = $paymentMethodEntity->getHandlerIdentifier();

            //If this is an Adyen PM installed it will only be enabled if it's present in the /paymentMethods response
            if (strpos($paymentMethodEntity->getFormattedHandlerIdentifier(), 'adyen')) {
                $pmHandler = new $pmHandlerIdentifier;
                $pmCode = $pmHandler->getAdyenPaymentMethodId();
                $pmFound = array_filter($adyenPaymentMethods['paymentMethods'],
                    function ($value) use ($pmCode) {
                        return $value['type'] == $pmCode;
                    });
                if (empty($pmFound)) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                }
            }
        }

        //Setting Shopware's payment methods list to the decorated/filtered list
        $page->setPaymentMethods($originalPaymentMethods);
        return $page;
    }
}
