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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service;

use Adyen\Environment;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    const BUNDLE_NAME = 'AdyenPaymentShopware6';

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EntityRepository|\Shopware\Core\Content\Media\DataAbstractionLayer\MediaRepositoryDecorator
     */
    private $mediaRepository;

    /**
     * ConfigurationService constructor.
     *
     * @param SystemConfigService $systemConfigService
     * @param mixed $mediaRepository
     * @note `media.repository` service is decorated in Shopware 6.4 and does not return EntityRepository object
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        $mediaRepository
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->mediaRepository = $mediaRepository;
    }

    /**
     * @param string|null $salesChannelId
     * @return array|mixed|null
     */
    public function getMerchantAccount(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.merchantAccount', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getApiKeyTest(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.apiKeyTest', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getApiKeyLive(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.apiKeyLive', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    private function getClientKeyTest(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.clientKeyTest', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    private function getClientKeyLive(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.clientKeyLive', $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return string
     */
    public function getEnvironment(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.environment', $salesChannelId) ?
            Environment::LIVE : Environment::TEST;
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getLiveEndpointUrlPrefix(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.liveEndpointUrlPrefix',
            $salesChannelId
        );
    }

    /**
     * @param string|null $salesChannelId
     * @return array|mixed|null
     */
    public function getNotificationUsername(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.notificationUsername',
            $salesChannelId
        );
    }

    /**
     * @param string|null $salesChannelId
     * @return array|mixed|null
     */
    public function getNotificationPassword(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.notificationPassword',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getHmacTest(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.hmacTest', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getHmacLive(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.hmacLive', $salesChannelId);
    }

    /**
     * Returns HMAC Key based on the configured environment
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getHmacKey(string $salesChannelId)
    {
        if ($this->getEnvironment($salesChannelId) === Environment::LIVE) {
            return $this->getHmacLive($salesChannelId);
        }

        return $this->getHmacTest($salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getApiKey(string $salesChannelId)
    {
        if ($this->getEnvironment($salesChannelId) === Environment::LIVE) {
            return $this->getApiKeyLive($salesChannelId);
        }

        return $this->getApiKeyTest($salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getClientKey(string $salesChannelId)
    {
        if ($this->getEnvironment($salesChannelId) === Environment::LIVE) {
            return $this->getClientKeyLive($salesChannelId);
        }

        return $this->getClientKeyTest($salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return array|bool|float|int|string|null
     */
    public function isManualCaptureActive(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.manualCaptureEnabled', $salesChannelId);
    }

    /**
     * Merchant account should be authorised in order to use auto capture for open invoices.
     * This can be done via reaching out to Adyen support.
     *
     * @param string|null $salesChannelId
     * @return array|bool|float|int|string|null
     */
    public function isAutoCaptureActiveForOpenInvoices(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.autoCaptureOpenInvoice', $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return array|bool|float|int|string|null
     */
    public function isCaptureOnShipmentEnabled(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.captureOnShipmentEnabled',
            $salesChannelId
        );
    }

    /**
     * @param string|null $salesChannelId
     * @return array|bool|float|int|string|null
     */
    public function getRescheduleTime(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.rescheduleTime', $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return array|bool|float|int|string|null
     */
    public function getOrderState(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.orderState', $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return array|mixed|null
     */
    public function getDeviceFingerprintSnippetId(string $salesChannelId = null)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.deviceFingerprintSnippetId',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function isAdyenGivingEnabled(string $salesChannelId)
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.adyenGivingEnabled', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingCharityMerchantAccount(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingCharityMerchantAccount',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingDonationAmounts(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingDonationAmounts',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingBackgroundUrl(string $salesChannelId, Context $context)
    {
        $backgroundImageMediaId = $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingBackgroundImage',
            $salesChannelId
        );

        if (!is_null($backgroundImageMediaId)) {
            $criteria = new Criteria([$backgroundImageMediaId]);
            $mediaCollection = $this->mediaRepository->search($criteria, $context);
            $backgroundMedia = $mediaCollection->get($backgroundImageMediaId);

            return $backgroundMedia->url;
        }

        return null;
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingCharityLogo(string $salesChannelId, Context $context)
    {
        $charityLogoMediaId = $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingCharityLogo',
            $salesChannelId
        );

        if (!is_null($charityLogoMediaId)) {
            $criteria = new Criteria([$charityLogoMediaId]);
            $mediaCollection = $this->mediaRepository->search($criteria, $context);
            $charityLogoMedia = $mediaCollection->get($charityLogoMediaId);

            return $charityLogoMedia->url;
        }

        return null;
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingCharityDescription(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingCharityDescription',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingCharityName(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingCharityName',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getAdyenGivingCharityWebsite(string $salesChannelId)
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.adyenGivingCharityWebsite',
            $salesChannelId
        );
    }

    /**
     * @param string $salesChannelId
     * @return array|mixed|null
     */
    public function getDefaultDomainId(string $salesChannelId): ?string
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.defaultDomainId', $salesChannelId);
    }

    /**
     * @param string $salesChannelId
     * @return null|bool
     */
    public function getIsOverrideDefaultDomainEnabled(string $salesChannelId): ?bool
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.enableOverrideDefaultDomain',
            $salesChannelId
        );
    }

    /**
     * Returns the refund strategy (FIFO, FILO or ratio) for gift card partial payments.
     *
     * @param string $salesChannelId
     * @return string|null
     */
    public function getRefundStrategyForGiftcards(string $salesChannelId): ?string
    {
        return $this->systemConfigService->get(
            self::BUNDLE_NAME . '.config.refundStrategyGiftcard',
            $salesChannelId
        );
    }
}
