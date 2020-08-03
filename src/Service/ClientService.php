<?php
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

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Store\Services\StoreService;

class ClientService extends \Adyen\Client
{
    const MERCHANT_APPLICATION_NAME = 'adyen-shopware6';
    const EXTERNAL_PLATFORM_NAME = 'Shopware';

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var LoggerInterface
     */
    private $genericLogger;

    /**
     * @var ContainerParametersService
     */
    private $containerParametersService;

    /**
     * @var StoreService
     */
    private $storeService;

    /**
     * Client constructor.
     *
     * @param LoggerInterface $genericLogger
     * @param LoggerInterface $apiLogger
     * @param ConfigurationService $configurationService
     * @param ContainerParametersService $containerParametersService
     * @param StoreService $storeService
     * @throws \Adyen\AdyenException
     */
    public function __construct(
        LoggerInterface $genericLogger,
        LoggerInterface $apiLogger,
        ConfigurationService $configurationService,
        ContainerParametersService $containerParametersService,
        StoreService $storeService
    ) {
        $this->configurationService = $configurationService;
        $this->genericLogger = $genericLogger;
        $this->containerParametersService = $containerParametersService;
        $this->storeService = $storeService;

        parent::__construct();

        try {
            $environment = $this->configurationService->getEnvironment();
            $apiKey = $this->configurationService->getApiKey();
            $liveEndpointUrlPrefix = $this->configurationService->getLiveEndpointUrlPrefix();

            $this->setXApiKey($apiKey);
            $this->setMerchantApplication(self::MERCHANT_APPLICATION_NAME, $this->getModuleVersion());
            $this->setExternalPlatform(self::EXTERNAL_PLATFORM_NAME, $this->storeService->getShopwareVersion());
            $this->setEnvironment($environment, $liveEndpointUrlPrefix);

            $this->setLogger($apiLogger);
            //TODO set $this->configuration
        } catch (\Exception $e) {
            $this->genericLogger->error($e->getMessage());
            // TODO: check if $environment is test and, if so, exit with error message
        }
    }

    /**
     * Get adyen module's version from composer.json
     *
     * @return string
     */
    public function getModuleVersion()
    {
        $rootDir = $this->containerParametersService->getApplicationRootDir();

        $composerJson = file_get_contents($rootDir . '/custom/plugins/adyen-shopware6/composer.json');

        $composerJson = json_decode($composerJson, true);

        if (empty($composerJson['version'])) {
            $this->genericLogger->error('Adyen plugin version is not available in composer.json');
            return "NA";
        }

        return $composerJson['version'];
    }
}
