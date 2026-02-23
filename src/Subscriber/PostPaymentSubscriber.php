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
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class PostPaymentSubscriber extends StorefrontSubscriber implements EventSubscriberInterface
{
    const ACTION_TYPE_VOUCHER = 'voucher';

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var SalesChannelRepository
     */
    private SalesChannelRepository $salesChannelRepository;

    /**
     * @var Currency
     */
    private Currency $currency;

    /**
     * @var RouterInterface
     */
    private RouterInterface $router;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var OrderTransactionRepository
     */
    private OrderTransactionRepository $orderTransactionRepository;

    /**
     * @var ExpressCheckoutService
     */
    private ExpressCheckoutService $expressCheckoutService;

    /**
     * @var TermsAndConditionsService
     */
    private TermsAndConditionsService $termsAndConditionsService;
    private RequestStack $requestStack;

    /**
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param RouterInterface $router
     * @param OrderTransactionRepository $orderTransactionRepository
     * @param ExpressCheckoutService $expressCheckoutService
     * @param TermsAndConditionsService $termsAndConditionsService
     * @param LoggerInterface $logger
     * @param RequestStack $requestStack
     */
    public function __construct(
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        Currency $currency,
        RouterInterface $router,
        OrderTransactionRepository $orderTransactionRepository,
        ExpressCheckoutService $expressCheckoutService,
        TermsAndConditionsService $termsAndConditionsService,
        LoggerInterface $logger,
        RequestStack $requestStack
    ) {
        $this->configurationService = $configurationService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->currency = $currency;
        $this->router = $router;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->expressCheckoutService = $expressCheckoutService;
        $this->termsAndConditionsService = $termsAndConditionsService;
        $this->logger = $logger;
        $this->requestStack = $requestStack;
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutFinishPageLoadedEvent::class => 'onCheckoutFinishPageLoaded'
        ];
    }

    /**
     * @param CheckoutFinishPageLoadedEvent $event
     */
    public function onCheckoutFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $this->requestStack->getSession()->set('adyenSwContextToken', $salesChannelContext->getToken());

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
        $frontendData = $this->setOrderProcessingNotification($frontendData, $order);

        $page->addExtension(
            self::ADYEN_DATA_EXTENSION_ID,
            new ArrayEntity($frontendData)
        );
    }

    /**
     * @param array $frontendData
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    private function buildAdyenGivingData(
        array $frontendData,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ): array {
        $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
            $order->getId(),
            [OrderTransactionStates::STATE_AUTHORIZED, OrderTransactionStates::STATE_IN_PROGRESS]
        );

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

        $termsAndConditionsUrl = $this->termsAndConditionsService->getTermsAndConditionsUrl($salesChannelContext);

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

    /**
     * @param array $frontendData
     * @param OrderEntity $order
     *
     * @return array
     */
    private function setOrderProcessingNotification(array $frontendData, OrderEntity $order): array
    {
        $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
            $order->getId(),
            [OrderTransactionStates::STATE_IN_PROGRESS]
        );

        if (is_null($orderTransaction)) {
            return $frontendData;
        }

        $customFields = $orderTransaction->getCustomFields();

        if (isset($customFields['resultCode']) &&
            in_array($customFields['resultCode'], ['Received', 'Pending'], true)) {
            $frontendData['notification'] = true;
        }

        return $frontendData;
    }

    /**
     * @param array $frontendData
     * @param OrderEntity $order
     *
     * @return array
     */
    private function buildVoucherActionData(array $frontendData, OrderEntity $order): array
    {
        $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
            $order->getId(),
            [OrderTransactionStates::STATE_IN_PROGRESS]
        );

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
