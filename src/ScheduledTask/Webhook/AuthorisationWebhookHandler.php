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
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

class AuthorisationWebhookHandler implements WebhookHandlerInterface
{
    use LoggerAwareTrait;

    /**
     * @var CaptureService
     */
    private $captureService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

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
            $this->handleFailedNotification($orderTransactionEntity, $context);
        }
    }

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

    private function handleFailedNotification($orderTransactionEntity, $context)
    {
        $this->orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);
    }
}
