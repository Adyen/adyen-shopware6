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

use Adyen\AdyenException;
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

    /**
     * @throws AdyenException
     */
    public function getClient($salesChannelId)
    {
        try {
            if (empty($salesChannelId)) {
                throw new AdyenException('The sales channel ID has not been configured.');
            }
            $environment = $this->configurationService->getEnvironment($salesChannelId);
            $apiKey = $this->configurationService->getApiKey($salesChannelId);
            $liveEndpointUrlPrefix = $this->configurationService->getLiveEndpointUrlPrefix($salesChannelId);

            $client = new Client();
            $client->setXApiKey($apiKey);
            $client->setMerchantApplication(self::MERCHANT_APPLICATION_NAME, $this->getModuleVersion());
            $client->setExternalPlatform(self::EXTERNAL_PLATFORM_NAME, $this->shopwareVersion);
            $client->setEnvironment($environment, $liveEndpointUrlPrefix);

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

    /**
     * @param array $request
     * @param string $apiVersion
     * @param string $endpoint
     * @param string $salesChannelId
     * @return void
     */
    public function logRequest(array $request, string $apiVersion, string $endpoint, string $salesChannelId)
    {
        $environment = $this->configurationService->getEnvironment($salesChannelId);
        $context = ['apiVersion' => $apiVersion];

        if ($environment === Environment::TEST) {
            $context['body'] = $request;
        } else {
            $context['livePrefix'] = $this->configurationService->getLiveEndpointUrlPrefix($salesChannelId);
            $context['body'] = $this->filterReferences($request);
        }

        $this->apiLogger->info('Request to Adyen API ' . $endpoint, $context);
    }

    /**
     * @param array $response
     * @param string $salesChannelId
     * @return void
     */
    public function logResponse(array $response, string $salesChannelId)
    {
        $environment = $this->configurationService->getEnvironment($salesChannelId);
        $context = [];

        if ($environment === Environment::TEST) {
            $context['body'] = $response;
        } else {
            $context['body'] = $this->filterReferences($response);
        }

        $this->apiLogger->info('Response from Adyen API', $context);
    }

    /**
     * @param array $data
     * @return array
     */
    private function filterReferences(array $data): array
    {
        return array_filter($data, function ($value, $key) {
            // Keep only reference keys, e.g. reference, pspReference, merchantReference etc.
            return false !== strpos(strtolower($key), 'reference');
        }, ARRAY_FILTER_USE_BOTH);
    }
}
