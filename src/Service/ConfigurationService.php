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

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    const BUNDLE_NAME = 'AdyenPayment';

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * ConfigurationService constructor.
     *
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(
        SystemConfigService $systemConfigService
    ) {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @return array|mixed|null
     */
    public function getMerchantAccount()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.merchantAccount');
    }

    /**
     * @return array|mixed|null
     */
    public function getApiKeyTest()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.apiKeyTest');
    }

    /**
     * @return array|mixed|null
     */
    public function getApiKeyLive()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.apiKeyLive');
    }

    /**
     * @return array|mixed|null
     */
    public function getEnvironment()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.environment');
    }

    /**
     * @return array|mixed|null
     */
    public function getLiveEndpointUrlPrefix()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.liveEndpointUrlPrefix');
    }

    /**
     * @return array|mixed|null
     */
    public function getNotificationUsername()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.notificationUsername');
    }

    /**
     * @return array|mixed|null
     */
    public function getNotificationPassword()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.notificationPassword');
    }

    /**
     * @return array|mixed|null
     */
    public function getHmacTest()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.hmacTest');
    }

    /**
     * @return array|mixed|null
     */
    public function getHmacLive()
    {
        return $this->systemConfigService->get(self::BUNDLE_NAME . '.config.hmacLive');
    }

    /**
     * Returns HMAC Key based on the configured environment
     * @return array|mixed|null
     */
    public function getHmacKey()
    {
        if ($this->getEnvironment()) {
            return $this->getHmacLive();
        }

        return $this->getHmacTest();
    }

    /**
     * @return array|mixed|null
     */
    public function getApiKey()
    {
        if ($this->getEnvironment()) {
            return $this->getApiKeyLive();
        }

        return $this->getApiKeyTest();
    }
}
