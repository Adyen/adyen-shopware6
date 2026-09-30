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

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Environment;
use Adyen\HttpClient\ClientInterface;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentReversalService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Shopware\Util\Idempotency;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class PaymentReversalServiceTest extends AdyenTestCase
{
    private MockObject $httpClient;
    private MockObject $configurationService;
    private MockObject $logger;
    private PaymentReversalService $paymentReversalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(ClientInterface::class);

        $client = new Client();
        $client->setEnvironment(Environment::TEST);
        $client->setHttpClient($this->httpClient);

        $clientService = $this->getSimpleMock(ClientService::class);
        $clientService->method('getClient')->willReturn($client);

        $this->configurationService = $this->getSimpleMock(ConfigurationService::class);
        $this->configurationService->method('getMerchantAccount')->willReturnMap([
            ['sales-channel-1', 'TestMerchant'],
            ['sales-channel-2', null],
        ]);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->paymentReversalService = new PaymentReversalService(
            $clientService,
            $this->configurationService,
            new Idempotency(),
            $this->logger
        );
    }

    public function testReverseSendsReversalRequestAndReturnsReversalPspReference(): void
    {
        $expectedIdempotencyKey = (new Idempotency())->createKeyFromRequest(
            ['merchantAccount' => 'TestMerchant', 'reference' => '10001'],
            ['pspReference' => 'PSP123']
        );

        $this->httpClient->expects($this->once())
            ->method('requestHttp')
            ->with(
                $this->anything(),
                $this->stringEndsWith('/payments/PSP123/reversals'),
                ['merchantAccount' => 'TestMerchant', 'reference' => '10001'],
                'post',
                ['idempotencyKey' => $expectedIdempotencyKey]
            )
            ->willReturn([
                'merchantAccount' => 'TestMerchant',
                'paymentPspReference' => 'PSP123',
                'pspReference' => 'REVERSAL1',
                'reference' => '10001',
                'status' => 'received',
            ]);

        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('critical');

        $this->assertSame(
            'REVERSAL1',
            $this->paymentReversalService->reverse('sales-channel-1', 'PSP123', '10001')
        );
    }

    public function testReverseUsesTheSameIdempotencyKeyForTheSamePayment(): void
    {
        $idempotencyKeys = [];
        $this->httpClient->method('requestHttp')->willReturnCallback(
            function ($service, $url, $params, $method, $requestOptions) use (&$idempotencyKeys) {
                $idempotencyKeys[] = $requestOptions['idempotencyKey'];

                return ['pspReference' => 'REVERSAL1', 'status' => 'received'];
            }
        );

        $this->paymentReversalService->reverse('sales-channel-1', 'PSP123', '10001', ['trigger' => 'finalize']);
        $this->paymentReversalService->reverse('sales-channel-1', 'PSP123', '10001', ['trigger' => 'webhook']);

        $this->assertCount(2, $idempotencyKeys);
        $this->assertSame($idempotencyKeys[0], $idempotencyKeys[1]);
    }

    public function testReverseReturnsNullAndLogsCriticalWhenTheApiCallFails(): void
    {
        $this->httpClient->method('requestHttp')->willThrowException(new AdyenException('Payment already refunded'));

        $this->logger->expects($this->once())
            ->method('critical')
            ->with($this->anything(), $this->callback(function (array $context) {
                return $context['pspReference'] === 'PSP123'
                    && $context['merchantReference'] === '10001'
                    && $context['errorMessage'] === 'Payment already refunded';
            }));

        $this->assertNull($this->paymentReversalService->reverse('sales-channel-1', 'PSP123', '10001'));
    }

    public function testReverseReturnsNullWhenTheResponseHasNoPspReference(): void
    {
        $this->httpClient->method('requestHttp')->willReturn([]);

        $this->logger->expects($this->once())->method('critical');

        $this->assertNull($this->paymentReversalService->reverse('sales-channel-1', 'PSP123', '10001'));
    }

    public function testReverseDoesNotCallTheApiWithoutMerchantAccount(): void
    {
        $this->httpClient->expects($this->never())->method('requestHttp');
        $this->logger->expects($this->once())->method('critical');

        $this->assertNull($this->paymentReversalService->reverse('sales-channel-2', 'PSP123', '10001'));
    }
}
