<?php declare(strict_types=1);
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Subscriber;

use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\ExpressCheckoutService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Service\TermsAndConditionsService;
use Adyen\Shopware\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class PostPaymentSubscriber extends StorefrontSubscriber implements EventSubscriberInterface
{
    const ACTION_TYPE_VOUCHER = 'voucher';

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var Currency
     */
    private $currency;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ExpressCheckoutService
     */
    private $expressCheckoutService;

    /**
     * @var TermsAndConditionsService
     */
    private $termsAndConditionsService;

    /**
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param RouterInterface $router
     * @param ExpressCheckoutService $expressCheckoutService
     * @param TermsAndConditionsService $expressCheckoutService
     * @param LoggerInterface $logger
     */
    public function __construct(
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        Currency $currency,
        RouterInterface $router,
        ExpressCheckoutService $expressCheckoutService,
        TermsAndConditionsService $termsAndConditionsService,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->currency = $currency;
        $this->router = $router;
        $this->expressCheckoutService = $expressCheckoutService;
        $this->termsAndConditionsService = $termsAndConditionsService;
        $this->logger = $logger;
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents() : array
    {
        return [
            CheckoutFinishPageLoadedEvent::class => 'onCheckoutFinishPageLoaded'
        ];
    }

    /**
     * @param CheckoutFinishPageLoadedEvent $event
     */
    public function onCheckoutFinishPageLoaded(CheckoutFinishPageLoadedEvent $event)
    {
        $page = $event->getPage();
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $order = $page->getOrder();

        $frontendData = [
            'clientKey' => $this->configurationService->getClientKey($salesChannelId),
            'locale' => $this->salesChannelRepository
                ->getSalesChannelAssoc($salesChannelContext, ['language.locale'])
                ->getLanguage()->getLocale()->getCode(),
            'environment' => $this->configurationService->getEnvironment($salesChannelId),
            'orderId' => $order->getId(),
            'countryCode' => $this->expressCheckoutService->getCountryCode(
                $salesChannelContext->getCustomer(),
                $salesChannelContext
            ),
        ];

        if ($this->configurationService->isAdyenGivingEnabled($salesChannelId)) {
            $frontendData = $this->buildAdyenGivingData($frontendData, $order, $salesChannelContext);
        }

        $frontendData = $this->buildVoucherActionData($frontendData, $order);

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity($frontendData)
        );
    }

    private function buildAdyenGivingData(
        array $frontendData,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ): array {
        $orderTransaction = $order->getTransactions()
            ->filterByState(OrderTransactionStates::STATE_AUTHORIZED)->first();

        if (is_null($orderTransaction)) {
            return $frontendData;
        }

        $customFields = $orderTransaction->getCustomFields();

        if (!isset($customFields['donationToken'])) {
            return $frontendData;
        }

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $backgroundImageUrl = $this->configurationService->getAdyenGivingBackgroundUrl(
            $salesChannelId,
            $salesChannelContext->getContext()
        );
        $charityLogoUrl = $this->configurationService->getAdyenGivingCharityLogo(
            $salesChannelId,
            $salesChannelContext->getContext()
        );
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amounts = $this->configurationService->getAdyenGivingDonationAmounts($salesChannelId);

        $termsAndConditionsUrl = $this->configurationService->getAdyenGivingTermsAndConditionsUrl(
            $salesChannelContext->getSalesChannel()->getId()
        );

        if (empty($termsAndConditionsUrl)) {
            $tosPageId = $this->configurationService->getTosPageId(
                $salesChannelContext->getSalesChannel()->getId()
            );

            $termsAndConditionsPath = $this->termsAndConditionsService->getShopTermsAndConditionsPath(
                $tosPageId,
                $salesChannelContext
            );

            if (!empty($termsAndConditionsPath)) {
                $baseUrl = $this->salesChannelRepository->getCurrentDomainUrl($salesChannelContext);
                $termsAndConditionsUrl = $baseUrl . $termsAndConditionsPath;
            }
        }

        $donationAmounts = [];
        try {
            foreach (explode(',', $amounts) as $donationAmount) {
                $donationAmounts[] = $this->currency->sanitize($donationAmount, $currency);
            }
            $donationAmounts = implode(',', $donationAmounts);
        } catch (\Exception $e) {
            $this->logger->error("Field 'donationAmounts' is not valid.");
            return $frontendData;
        }

        $adyenGivingData = [
            'givingEnabled' => true,
            'currency' => $currency,
            'values' => $donationAmounts,
            'backgroundUrl' => $backgroundImageUrl,
            'logoUrl' => $charityLogoUrl,
            'description' => $this->configurationService->getAdyenGivingCharityDescription($salesChannelId),
            'name' => $this->configurationService->getAdyenGivingCharityName($salesChannelId),
            'charityUrl' => $this->configurationService->getAdyenGivingCharityWebsite($salesChannelId),
            'donationEndpointUrl' => $this->router->generate(
                'payment.adyen.proxy-donate'
            ),
            'continueActionUrl' => $this->router->generate(
                'frontend.home.page'
            ),
            'termsAndConditionsUrl' => $termsAndConditionsUrl
        ];

        return array_merge($frontendData, $adyenGivingData);
    }

    private function buildVoucherActionData($frontendData, $order)
    {
        $orderTransaction = $order->getTransactions()
            ->filterByState(OrderTransactionStates::STATE_IN_PROGRESS)->first();

        if (is_null($orderTransaction)) {
            return $frontendData;
        }

        $customFields = $orderTransaction->getCustomFields();

        if (isset($customFields['action']) && $customFields['action']['type'] == self::ACTION_TYPE_VOUCHER) {
            $voucherData = [
                'action' => json_encode($customFields['action'])
            ];

            return array_merge($frontendData, $voucherData);
        }

        return $frontendData;
    }
}
