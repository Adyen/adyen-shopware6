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
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Struct\ArrayEntity;
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
     * @param SalesChannelRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param RouterInterface $router
     * @param LoggerInterface $logger
     */
    public function __construct(
        SalesChannelRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        Currency $currency,
        RouterInterface $router,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->currency = $currency;
        $this->router = $router;
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

    private function buildAdyenGivingData($frontendData, $order, $salesChannelContext)
    {
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
            )
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
