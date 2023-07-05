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
use Adyen\Environment;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Psr\Cache\CacheItemPoolInterface;

class ClientService
{
    const MERCHANT_APPLICATION_NAME = 'adyen-shopware6';
    const EXTERNAL_PLATFORM_NAME = 'Shopware6';
    const MODULE_VERSION_CACHE_TAG = 'adyen_module_version';

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
     * @var string
     */
    private $shopwareVersion;

    /**
     * @var EntityRepository
     */
    private $pluginRepository;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * Client constructor.
     *
     * @param EntityRepository $pluginRepository
     * @param LoggerInterface $genericLogger
     * @param LoggerInterface $apiLogger
     * @param string $shopwareVersion
     * @param ConfigurationService $configurationService
     * @param CacheItemPoolInterface $cache
     */
    public function __construct(
        EntityRepository $pluginRepository,
        LoggerInterface $genericLogger,
        LoggerInterface $apiLogger,
        string $shopwareVersion,
        ConfigurationService $configurationService,
        CacheItemPoolInterface $cache
    ) {
        $this->pluginRepository = $pluginRepository;
        $this->configurationService = $configurationService;
        $this->genericLogger = $genericLogger;
        $this->apiLogger = $apiLogger;
        $this->cache = $cache;
        $this->shopwareVersion = $shopwareVersion;
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
            $client->setExternalPlatform(self::EXTERNAL_PLATFORM_NAME, $this->shopwareVersion);
            $client->setEnvironment($environment, $liveEndpointUrlPrefix);

            $client->setLogger($this->apiLogger);

            return $client;
        } catch (\Exception $e) {
            $this->genericLogger->error($e->getMessage());
            if ($this->configurationService->getEnvironment($salesChannelId) === Environment::TEST) {
                throw $e;
            }
        }
    }

    /**
     * Get adyen module's version from cache if exists or from PluginEntity
     * Stores the module version in the cache when empty
     *
     * @return string
     */
    public function getModuleVersion()
    {
        try {
            $moduleVersionCacheItem = $this->cache->getItem(self::MODULE_VERSION_CACHE_TAG);
        } catch (\Psr\Cache\InvalidArgumentException $e) {
            $this->genericLogger->error($e->getMessage());
        }

        //Prefer the cached module version
        if (!$moduleVersionCacheItem->isHit() || !$moduleVersionCacheItem->get()) {
            /** @var PluginEntity $pluginEntity */
            $pluginEntity = $this->pluginRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('name', ConfigurationService::BUNDLE_NAME)),
                Context::createDefaultContext()
            )->first();
            $pluginVersion = $pluginEntity->getVersion();
            $moduleVersionCacheItem->set($pluginVersion);
            $this->cache->save($moduleVersionCacheItem);
        } else {
            $pluginVersion = $moduleVersionCacheItem->get();
        }

        if (empty($pluginVersion)) {
            $this->genericLogger->error('Adyen plugin version is not available');
            return "NA";
        }

        return $pluginVersion;
    }
}
