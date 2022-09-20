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
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\Service\CaptureService;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

class CaptureWebhookHandler implements WebhookHandlerInterface
{
    use LoggerAwareTrait;

    /**
     * @var CaptureService
     */
    private $captureService;

    /**
     * @var OrderTransactionStateHandler
     */
    protected $orderTransactionStateHandler;

    /**
     * @param CaptureService $captureService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @return void
     */
    public function __construct(
        CaptureService $captureService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->captureService = $captureService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
    }

    public function handleWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        string $state,
        string $currentTransactionState,
        Context $context
    ) {
        if ($notificationEntity->isSuccess() && $state !== $currentTransactionState) {
            $this->handleSuccessfulNotification($orderTransactionEntity, $notificationEntity, $context);
        }
        else {
            $this->handleFailedNotification($orderTransactionEntity, $notificationEntity, $context);
        }
    }

    private function handleSuccessfulNotification($orderTransaction, $notification, $context)
    {
        $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $context);

        $this->logger->info(
            'Handling CAPTURE notification',
            ['order' => $orderTransaction->getOrder()->getVars(), 'notification' => $notification->getVars()]
        );

        $this->captureService->handleCaptureNotification(
            $orderTransaction,
            $notification,
            PaymentCaptureEntity::STATUS_SUCCESS,
            $context
        );
    }

    private function handleFailedNotification($orderTransactionEntity, $notificationEntity, $context)
    {
        $this->orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);

        $this->captureService->handleCaptureNotification(
            $orderTransactionEntity,
            $notificationEntity,
            PaymentCaptureEntity::STATUS_FAILED,
            $context
        );
    }
}
