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
use Adyen\Environment;
use Adyen\HttpClient\ClientInterface;
use Adyen\Model\Checkout\ApplicationInfo;
use Adyen\Model\Checkout\CommonField;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\DonationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class DonationServiceTest extends AdyenTestCase
{
    public function testDonateSendsApplicationInfo(): void
    {
        $sentParams = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('requestHttp')->willReturnCallback(
            function ($service, $url, $params) use (&$sentParams) {
                $sentParams = json_decode(json_encode($params), true);

                return ['status' => 'completed'];
            }
        );

        $client = new Client();
        $client->setEnvironment(Environment::TEST);
        $client->setHttpClient($httpClient);

        $clientService = $this->getSimpleMock(ClientService::class);
        $clientService->method('getClient')->willReturn($client);
        $clientService->method('getApplicationInfo')->willReturn(new ApplicationInfo([
            'merchantApplication' => new CommonField(['name' => 'adyen-shopware6', 'version' => '5.5.0']),
        ]));

        $configurationService = $this->getSimpleMock(ConfigurationService::class);
        $configurationService->method('getMerchantAccount')->willReturn('TestMerchant');
        $configurationService->method('getAdyenGivingCharityMerchantAccount')->willReturn('CharityMerchant');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-1');
        $context = $this->getSimpleMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        (new DonationService($clientService, $configurationService))->donate(
            $context,
            'donation-token',
            'EUR',
            500,
            'https://shop.example/return',
            'PSP123',
            'scheme'
        );

        $this->assertSame(
            ['merchantApplication' => ['name' => 'adyen-shopware6', 'version' => '5.5.0']],
            $sentParams['applicationInfo'] ?? null
        );
    }
}
