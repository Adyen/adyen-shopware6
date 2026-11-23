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

use Adyen\Shopware\Handlers\GiftCardPaymentMethodHandler;
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\ScheduledTask\Webhook\AuthorisationWebhookHandler;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PluginPaymentMethodsService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Shopware\Test\Common\CaptureWebhookTestTrait;
use Adyen\Webhook\EventCodes;
use Adyen\Webhook\PaymentStates;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class AuthorisationWebhookHandlerTest extends AdyenTestCase
{
    use CaptureWebhookTestTrait;

    private MockObject $captureService;
    private MockObject $adyenPaymentService;
    private MockObject $stateHandler;
    private MockObject $pluginPaymentMethodsService;
    private AuthorisationWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureService = $this->getSimpleMock(CaptureService::class);
        $this->adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $this->pluginPaymentMethodsService = $this->getSimpleMock(PluginPaymentMethodsService::class);

        $this->handler = new AuthorisationWebhookHandler(
            $this->captureService,
            $this->adyenPaymentService,
            $this->stateHandler,
            $this->pluginPaymentMethodsService,
            $this->getSimpleMock(ConfigurationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testImmediateCaptureSetsTheTransactionToPaid(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::IMMEDIATE));

        $this->adyenPaymentService->expects(self::once())
            ->method('insertAdyenPayment')
            ->with(self::anything(), $transaction, false);
        $this->stateHandler->expects(self::once())->method('paid')->with('transaction-1');
        $this->stateHandler->expects(self::never())->method('authorize');

        $this->handle($transaction, PaymentStates::STATE_PAID, PaymentStates::STATE_IN_PROGRESS);
    }

    public function testManualCaptureAuthorisesWithoutCapturing(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::MANUAL));

        $this->adyenPaymentService->expects(self::once())
            ->method('insertAdyenPayment')
            ->with(self::anything(), $transaction, true);
        $this->stateHandler->expects(self::once())->method('authorize')->with('transaction-1');
        $this->stateHandler->expects(self::never())->method('paid');
        $this->captureService->expects(self::never())->method('doCapture');

        $this->handle($transaction, PaymentStates::STATE_AUTHORIZED, PaymentStates::STATE_IN_PROGRESS);
    }

    public function testCaptureOnShipmentAuthorisesAndTriesToCapture(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 60));

        $this->stateHandler->expects(self::once())->method('authorize');
        $this->captureService->expects(self::once())
            ->method('doCapture')
            ->with('10001', '1999', self::isInstanceOf(Context::class));

        $this->handle($transaction, PaymentStates::STATE_AUTHORIZED, PaymentStates::STATE_IN_PROGRESS);
    }

    /**
     * Q6: on a retry the transaction is already authorised. Calling authorize() again would throw.
     */
    public function testRetryOnAnAuthorisedTransactionCapturesWithoutAuthorisingAgain(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_AUTHORIZED);
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 60));

        $this->stateHandler->expects(self::never())->method('authorize');
        $this->captureService->expects(self::once())->method('doCapture');

        $this->handle($transaction, PaymentStates::STATE_AUTHORIZED, PaymentStates::STATE_PENDING);
    }

    public function testGiftCardOfAPartialPaymentIsStoredAsAutoCapture(): void
    {
        $transaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $notification = $this->createCaptureNotification(EventCodes::AUTHORISATION, 'givex', 'order-reference');

        $this->pluginPaymentMethodsService->method('getGiftcardHandlerIdentifierFromTxVariant')
            ->with('givex')
            ->willReturn(GiftCardPaymentMethodHandler::class);
        $this->captureService->expects(self::never())->method('getCaptureConfiguration');
        $this->adyenPaymentService->expects(self::once())
            ->method('insertAdyenPayment')
            ->with($notification, $transaction, false);
        $this->stateHandler->expects(self::never())->method('authorize');
        $this->stateHandler->expects(self::never())->method('paid');

        $this->handler->handleWebhook(
            $transaction,
            $notification,
            PaymentStates::STATE_AUTHORIZED,
            PaymentStates::STATE_IN_PROGRESS,
            Context::createDefaultContext()
        );
    }

    private function givenCaptureConfiguration(CaptureConfiguration $configuration): void
    {
        $this->captureService->method('getCaptureConfiguration')
            ->with(self::anything(), 'sales-channel-1')
            ->willReturn($configuration);
    }

    private function handle($transaction, string $state, string $currentState): void
    {
        $this->handler->handleWebhook(
            $transaction,
            $this->createCaptureNotification(EventCodes::AUTHORISATION),
            $state,
            $currentState,
            Context::createDefaultContext()
        );
    }
}
