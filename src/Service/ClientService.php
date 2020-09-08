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

use Adyen\Client;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Store\Services\StoreService;

class ClientService extends Client
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
     * @var LoggerInterface
     */
    private $apiLogger;

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
        $this->apiLogger = $apiLogger;
        $this->containerParametersService = $containerParametersService;
        $this->storeService = $storeService;
    }

    public function getClient($salesChannelId)
    {
        try {
            if (empty($salesChannelId)) {
                throw new \Adyen\AdyenException('The sales channel ID has not been configured.');
            }
            $environment = $this->configurationService->getEnvironment($salesChannelId);
            $apiKey = $this->configurationService->getApiKey($salesChannelId);
            $liveEndpointUrlPrefix = $this->configurationService->getLiveEndpointUrlPrefix($salesChannelId);

            $client = new Client();
            $client->setXApiKey($apiKey);
            $client->setMerchantApplication(self::MERCHANT_APPLICATION_NAME, $this->getModuleVersion());
            $client->setExternalPlatform(self::EXTERNAL_PLATFORM_NAME, $this->storeService->getShopwareVersion());
            $client->setEnvironment($environment, $liveEndpointUrlPrefix);

            $client->setLogger($this->apiLogger);

            return $client;
        } catch (\Exception $e) {
            $this->genericLogger->error($e->getMessage());
            // TODO: check if $environment is test and, if so, exit with error message
        }
    }

    /**
     * Get adyen module's version from composer.json
     * TODO: switch to a shopware service to retrieve the module version instead of composer
     *
     * @return string
     */
    public function getModuleVersion()
    {
        $rootDir = $this->containerParametersService->getApplicationRootDir();

        $composerJson = file_get_contents($rootDir . '/custom/plugins/adyen-shopware6/composer.json');

        if (false === $composerJson) {
            $this->genericLogger->error('composer.json is not available in the Adyen plugin folder');
            return "NA";
        }

        $composerJson = json_decode($composerJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->genericLogger->error('composer.json is not a valid JSON in the Adyen plugin folder');
            return "NA";
        }

        if (empty($composerJson['version'])) {
            $this->genericLogger->error('Adyen plugin version is not available in composer.json');
            return "NA";
        }

        return $composerJson['version'];
    }
}
