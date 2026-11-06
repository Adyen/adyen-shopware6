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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\ScheduledTask\Webhook\OrderClosedWebhookHandler;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Shopware\Test\Common\CaptureWebhookTestTrait;
use Adyen\Webhook\EventCodes;
use Adyen\Webhook\PaymentStates;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class OrderClosedWebhookHandlerTest extends AdyenTestCase
{
    use CaptureWebhookTestTrait;

    private MockObject $captureService;
    private MockObject $adyenPaymentService;
    private MockObject $stateHandler;
    private OrderClosedWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureService = $this->getSimpleMock(CaptureService::class);
        $this->adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);

        $this->handler = new OrderClosedWebhookHandler(
            $this->adyenPaymentService,
            $this->captureService,
            $this->stateHandler,
            $this->getSimpleMock(ConfigurationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testCaptureOnShipmentCapturesTheRequiredAmountOfAnAuthorisedTransaction(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_AUTHORIZED);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 60));
        $this->adyenPaymentService->method('isFullAmountAuthorized')->willReturn(true);
        $this->adyenPaymentService->method('getMerchantReferenceFromOrderReference')->willReturn('10001');
        $this->captureService->method('getRequiredCaptureAmount')->with('order-1')->willReturn(1999);

        $this->stateHandler->expects(self::never())->method('authorize');
        $this->captureService->expects(self::once())
            ->method('doCapture')
            ->with('10001', 1999, self::isInstanceOf(Context::class));

        $this->handle($transaction);
    }

    public function testManualCaptureAuthorisesAnOpenTransaction(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::MANUAL));

        $this->stateHandler->expects(self::once())->method('authorize')->with('transaction-1');
        $this->captureService->expects(self::never())->method('doCapture');

        $this->handle($transaction);
    }

    /**
     * Q6: after a manual capture the transaction is paid. ORDER_CLOSED has no state guard, so without the check it
     * would call authorize() on a paid transaction, which throws.
     */
    public function testPaidTransactionIsLeftAlone(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_PAID);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 60));

        $this->stateHandler->expects(self::never())->method('authorize');
        $this->stateHandler->expects(self::never())->method('paid');
        $this->captureService->expects(self::never())->method('doCapture');

        $this->handle($transaction);
    }

    public function testDelayedCaptureAuthorisesAnOpenTransactionWithoutCapturing(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::DELAYED, 2));

        $this->stateHandler->expects(self::once())->method('authorize')->with('transaction-1');
        $this->stateHandler->expects(self::never())->method('paid');
        $this->captureService->expects(self::never())->method('doCapture');

        $this->handle($transaction);
    }

    public function testImmediateCaptureSetsTheTransactionToPaid(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::IMMEDIATE));

        $this->stateHandler->expects(self::once())->method('paid')->with('transaction-1');
        $this->stateHandler->expects(self::never())->method('authorize');

        $this->handle($transaction);
    }

    private function givenCaptureConfiguration(CaptureConfiguration $configuration): void
    {
        $this->captureService->method('getCaptureConfiguration')
            ->with(self::anything(), 'sales-channel-1')
            ->willReturn($configuration);
    }

    private function handle(OrderTransactionEntity $transaction): void
    {
        $this->handler->handleWebhook(
            $transaction,
            $this->createCaptureNotification(EventCodes::ORDER_CLOSED),
            PaymentStates::STATE_PAID,
            PaymentStates::STATE_IN_PROGRESS,
            Context::createDefaultContext()
        );
    }
}
