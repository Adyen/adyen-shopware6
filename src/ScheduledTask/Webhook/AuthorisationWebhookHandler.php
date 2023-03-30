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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask\Webhook;

use Adyen\AdyenException;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\PluginPaymentMethodsService;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

class AuthorisationWebhookHandler implements WebhookHandlerInterface
{
    /** @var LoggerInterface */
    private $logger;

    /** @var CaptureService */
    private $captureService;

    /** @var AdyenPaymentService */
    private $adyenPaymentService;

    /** @var OrderTransactionStateHandler */
    private $orderTransactionStateHandler;

    /** @var PluginPaymentMethodsService */
    private $pluginPaymentMethodsService;

    /**
     * @param CaptureService $captureService
     * @param AdyenPaymentService $adyenPaymentService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param PluginPaymentMethodsService $pluginPaymentMethodsService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CaptureService $captureService,
        AdyenPaymentService $adyenPaymentService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        PluginPaymentMethodsService $pluginPaymentMethodsService,
        LoggerInterface $logger
    ) {
        $this->captureService = $captureService;
        $this->adyenPaymentService = $adyenPaymentService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->pluginPaymentMethodsService = $pluginPaymentMethodsService;
        $this->logger = $logger;
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param string $state
     * @param string $currentTransactionState
     * @param Context $context
     * @return void
     * @throws CaptureException|AdyenException
     */
    public function handleWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        string $state,
        string $currentTransactionState,
        Context $context
    ): void {
        if ($state !== $currentTransactionState) {
            if ($notificationEntity->isSuccess()) {
                $this->handleSuccessfulNotification($orderTransactionEntity, $notificationEntity, $context);
            } else {
                $this->handleFailedNotification($orderTransactionEntity, $context);
            }
        }
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @param Context $context
     * @return void
     * @throws AdyenException
     * @throws CaptureException
     */
    private function handleSuccessfulNotification(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification,
        Context $context
    ): void {
        $paymentMethodHandler = $this->pluginPaymentMethodsService->getHandlerIdentifierFromTxVariant(
            $notification->getPaymentMethod()
        );

        $isManualCapture = $this->captureService->requiresManualCapture($paymentMethodHandler);
        $currencyUtil = new Currency();
        $totalPrice = $orderTransaction->getAmount()->getTotalPrice();
        $isoCode = $orderTransaction->getOrder()->getCurrency()->getIsoCode();
        $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

        $this->adyenPaymentService->insertAdyenPayment($notification, $orderTransaction, $isManualCapture);

        // check for partial payments
        $merchantOrderReference = isset(json_decode($notification->getAdditionalData())->merchantOrderReference);
        if ($merchantOrderReference) {
            return;
        }

        if ($transactionAmount === intval($notification->getAmountValue())) {
            if ($isManualCapture) {
                $this->logger->info(
                    'Manual capture required. Setting payment to `authorised` state.',
                    ['notification' => $notification->getVars()]
                );
                $this->orderTransactionStateHandler->authorize($orderTransaction->getId(), $context);

                $this->logger->info(
                    'Attempting capture for open invoice payment.',
                    ['notification' => $notification->getVars()]
                );
                $this->captureService->doOpenInvoiceCapture(
                    $notification->getMerchantReference(),
                    $notification->getAmountValue(),
                    $context
                );
            } else {
                    $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $context);
            }
        }
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param Context $context
     * @return void
     */
    private function handleFailedNotification(OrderTransactionEntity $orderTransactionEntity, Context $context): void
    {
        $this->orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);
    }
}
