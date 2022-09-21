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

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Service\CaptureService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

class AuthorisationWebhookHandler implements WebhookHandlerInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CaptureService
     */
    private $captureService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @param CaptureService $captureService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param LoggerInterface $logger
     */
    public function __construct(
        CaptureService $captureService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        LoggerInterface $logger
    ) {
        $this->captureService = $captureService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->logger = $logger;
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param string $state
     * @param string $currentTransactionState
     * @param Context $context
     * @return mixed|void
     */
    public function handleWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        string $state,
        string $currentTransactionState,
        Context $context
    ) {
        if ($notificationEntity->isSuccess() && $state !== $currentTransactionState) {
            $this->handleSuccessfulNotification($orderTransactionEntity, $notificationEntity, $context);
        } else {
            $this->handleFailedNotification($orderTransactionEntity, $context);
        }
    }

    /**
     * @param $orderTransaction
     * @param $notification
     * @param $context
     * @return void
     * @throws \Adyen\Shopware\Exception\CaptureException
     */
    private function handleSuccessfulNotification($orderTransaction, $notification, $context)
    {
        $paymentMethodHandler = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();

        if ($this->captureService->requiresManualCapture($paymentMethodHandler)) {
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

    /**
     * @param $orderTransactionEntity
     * @param $context
     * @return void
     */
    private function handleFailedNotification($orderTransactionEntity, $context)
    {
        $this->orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);
    }
}
