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
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\Service\CaptureService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

class CaptureWebhookHandler implements WebhookHandlerInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var CaptureService
     */
    private CaptureService $captureService;

    /**
     * @var OrderTransactionStateHandler
     */
    protected OrderTransactionStateHandler $orderTransactionStateHandler;

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
     *
     * @return void
     */
    public function handleWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        string $state,
        string $currentTransactionState,
        Context $context
    ): void {
        if ($notificationEntity->isSuccess() && $state !== $currentTransactionState) {
            $this->handleSuccessfulNotification($orderTransactionEntity, $notificationEntity, $context);
        } else {
            $this->handleFailedNotification($orderTransactionEntity, $notificationEntity, $context);
        }
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @param Context $context
     *
     * @return void
     *
     * @throws AdyenException
     */
    private function handleSuccessfulNotification(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification,
        Context $context
    ): void {
        $this->logger->info(
            'Handling CAPTURE notification',
            ['order' => $orderTransaction->getOrder()->getVars(), 'notification' => $notification->getVars()]
        );

        $isRequiredAmountCapturedInNotification =
            $this->captureService->checkRequiredAmountFullyCapturedInNotification(
                $orderTransaction,
                $notification
            );

        if ($isRequiredAmountCapturedInNotification) {
            $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $context);
        } else {
            $this->logger->info(
                'Required amount was not fully captured. Payment state will not change.',
                ['order' => $orderTransaction->getOrder()->getVars(), 'notification' => $notification->getVars()]
            );
        }

        $this->captureService->handleCaptureNotification(
            $orderTransaction,
            $notification,
            PaymentCaptureEntity::STATUS_SUCCESS,
            $context
        );
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param Context $context
     *
     * @return void
     *
     * @throws AdyenException
     */
    private function handleFailedNotification(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        Context $context
    ): void {
        $this->orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);

        $this->captureService->handleCaptureNotification(
            $orderTransactionEntity,
            $notificationEntity,
            PaymentCaptureEntity::STATUS_FAILED,
            $context
        );
    }
}
