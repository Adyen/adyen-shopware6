<?php

namespace Adyen\Shopware\Storefront\Page\Checkout\Confirm;

use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\Util\SalesChannelUtil;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\OriginKeyService;

/**
 * Class AdyenCheckoutConfirmPageLoader
 * @package Adyen\Shopware\Storefront\Page\Checkout\Confirm
 */
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
     * @var OriginKeyService $originKeyService
     */
    private $originKeyService;

    /**
     * @var SalesChannelUtil
     */
    private $salesChannelUtil;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * CheckoutConfirmPageLoader constructor.
     * @param CheckoutConfirmPageLoader $checkoutConfirmPageLoader
     * @param CartService $cartService
     */
    public function __construct(
        CheckoutConfirmPageLoader $checkoutConfirmPageLoader,
        PaymentMethodsService $paymentMethodsService,
        OriginKeyService $originKeyService,
        SalesChannelUtil $salesChannelUtil,
        ConfigurationService $configurationService

    ) {
        $this->checkoutConfirmPageLoader = $checkoutConfirmPageLoader;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->originKeyService = $originKeyService;
        $this->salesChannelUtil = $salesChannelUtil;
        $this->configurationService = $configurationService;

    }

    /**
     * Original load() method being decorated to filter Shopware payment methods
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return CheckoutConfirmPage
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function load(Request $request, SalesChannelContext $salesChannelContext): CheckoutConfirmPage
    {
        //Get original page object and PM list to decorate
        $page = $this->checkoutConfirmPageLoader->load($request, $salesChannelContext);

        $adyenPage = AdyenCheckoutConfirmPage::createFrom($page);

        $originalPaymentMethods = $page->getPaymentMethods();

        //Setting Shopware's payment methods list to the decorated/filtered list
        $adyenPage->setPaymentMethods($this->filterShopwarePaymentMethods($originalPaymentMethods,
            $salesChannelContext));
        $adyenPage->setShippingMethods($page->getShippingMethods());

        //Setting Adyen data to be used in payment method forms
        $adyenPage->setAdyenData(
            [
                'originKey' => $this->originKeyService->getOriginKeyForOrigin(
                    $this->salesChannelUtil->getSalesChannelUrl($salesChannelContext)
                )->getOriginKey(),

                'locale' => $this->salesChannelUtil->getSalesChannelLocale($salesChannelContext)
                    ->getLanguage()->getLocale()->getCode(),

                'environment' => $this->configurationService->getEnvironment(),

                'paymentMethodsResponse' => json_encode(
                    $this->paymentMethodsService->getPaymentMethods($salesChannelContext)
                )

            ]
        );

        return $adyenPage;
    }

    /**
     * Removes payment methods from the Shopware list if not present in Adyen's /paymentMethods response
     *
     * @param $originalPaymentMethods
     * @param SalesChannelContext $salesChannelContext
     * @return mixed
     */
    private function filterShopwarePaymentMethods($originalPaymentMethods, SalesChannelContext $salesChannelContext)
    {
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
        return $originalPaymentMethods;
    }
}
