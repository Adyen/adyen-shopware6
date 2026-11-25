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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Client;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ClientServiceTest extends AdyenTestCase
{
    private ClientService $clientService;

    protected function setUp(): void
    {
        parent::setUp();

        $moduleVersionCacheItem = $this->createMock(CacheItemInterface::class);
        $moduleVersionCacheItem->method('isHit')->willReturn(true);
        $moduleVersionCacheItem->method('get')->willReturn('5.5.0');

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($moduleVersionCacheItem);

        $this->clientService = new ClientService(
            $this->getSimpleMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
            '6.6.10.0',
            $this->getSimpleMock(ConfigurationService::class),
            $cache
        );
    }

    public function testGetApplicationInfoDescribesLibraryPluginAndPlatform(): void
    {
        $applicationInfo = $this->clientService->getApplicationInfo();

        $this->assertSame(Client::LIB_NAME, $applicationInfo->getAdyenLibrary()->getName());
        $this->assertSame(Client::LIB_VERSION, $applicationInfo->getAdyenLibrary()->getVersion());
        $this->assertNull($applicationInfo->getAdyenPaymentSource());
        $this->assertSame('adyen-shopware6', $applicationInfo->getMerchantApplication()->getName());
        $this->assertSame('5.5.0', $applicationInfo->getMerchantApplication()->getVersion());
        $this->assertSame('Shopware6', $applicationInfo->getExternalPlatform()->getName());
        $this->assertSame('6.6.10.0', $applicationInfo->getExternalPlatform()->getVersion());
        $this->assertSame('Logeecom', $applicationInfo->getExternalPlatform()->getIntegrator());
    }
}
