<?php

namespace Adyen\Shopware\Storefront\Page\Checkout\Confirm;

use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\OriginKeyService;

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
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * CheckoutConfirmPageLoader constructor.
     * @param CheckoutConfirmPageLoader $checkoutConfirmPageLoader
     * @param PaymentMethodsService $paymentMethodsService
     * @param OriginKeyService $originKeyService
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        CheckoutConfirmPageLoader $checkoutConfirmPageLoader,
        PaymentMethodsService $paymentMethodsService,
        OriginKeyService $originKeyService,
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService
    ) {
        $this->checkoutConfirmPageLoader = $checkoutConfirmPageLoader;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->originKeyService = $originKeyService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
    }

    /**
     * Original load() method being decorated to filter Shopware payment methods
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return CheckoutConfirmPage
     * @throws InconsistentCriteriaIdsException|SalesChannelDomainNotFoundException
     */
    public function load(Request $request, SalesChannelContext $salesChannelContext): CheckoutConfirmPage
    {
        //Get original page object and PM list to decorate
        $page = $this->checkoutConfirmPageLoader->load($request, $salesChannelContext);

        $adyenPage = AdyenCheckoutConfirmPage::createFrom($page);

        $originalPaymentMethods = $page->getPaymentMethods();

        //Setting Shopware's payment methods list to the decorated/filtered list
        $adyenPage->setPaymentMethods($this->filterShopwarePaymentMethods(
            $originalPaymentMethods,
            $salesChannelContext
        ));
        $adyenPage->setShippingMethods($page->getShippingMethods());

        //Setting Adyen data to be used in payment method forms
        $adyenPage->setAdyenData(
            [
                'originKey' => $this->originKeyService->getOriginKeyForOrigin(
                    $this->salesChannelRepository->getSalesChannelUrl($salesChannelContext)
                )->getOriginKey(),

                'locale' => $this->salesChannelRepository->getSalesChannelAssocLocale($salesChannelContext)
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
        //TODO do this in an event instead
        //Adyen /paymentMethods response
        $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);

        foreach ($originalPaymentMethods as $paymentMethodEntity) {
            //TODO filter out unsupported PMs
            $pmHandlerIdentifier = $paymentMethodEntity->getHandlerIdentifier();

            //If this is an Adyen PM installed it will only be enabled if it's present in the /paymentMethods response
            if (strpos($paymentMethodEntity->getFormattedHandlerIdentifier(), 'adyen')) {
                $pmCode = 'scheme'; //TODO get from payment method handler instead of hardcoding PM type
                // In case the paymentMethods response has no payment methods, remove it from the list
                if (empty($adyenPaymentMethods)) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    continue;
                }

                $pmFound = array_filter(
                    $adyenPaymentMethods['paymentMethods'],
                    function ($value) use ($pmCode) {
                        return $value['type'] == $pmCode;
                    }
                );

                if (empty($pmFound)) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
                }
            }
        }
        return $originalPaymentMethods;
    }
}
