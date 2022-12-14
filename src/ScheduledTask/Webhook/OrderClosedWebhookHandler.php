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
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

class OrderClosedWebhookHandler implements WebhookHandlerInterface
{
    /**
     * @var AdyenPaymentService
     */
    private $adyenPaymentService;

    /**
     * @var CaptureService
     */
    private $captureService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        AdyenPaymentService $adyenPaymentService,
        CaptureService $captureService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->adyenPaymentService = $adyenPaymentService;
        $this->captureService = $captureService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param string $state
     * @param string $currentTransactionState
     * @param Context $context
     * @return void
     * @throws \Adyen\Shopware\Exception\CaptureException
     */
    public function handleWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        string $state,
        string $currentTransactionState,
        Context $context
    ): void {
        if ($notificationEntity->isSuccess()) {
            $this->handleSuccessfulNotification($orderTransactionEntity, $notificationEntity, $context);
        } else {
            $this->handleFailedNotification($orderTransactionEntity, $context);
        }
    }

    private function handleSuccessfulNotification(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        Context $context
    ): void {
        if ($this->adyenPaymentService->isFullAmountAuthorized(
            $notificationEntity->getMerchantReference(),
            $orderTransactionEntity
        )) {
            $paymentMethodHandler = $orderTransactionEntity->getPaymentMethod()->getHandlerIdentifier();
            if ($this->captureService->requiresManualCapture($paymentMethodHandler)) {
                $this->orderTransactionStateHandler->authorize($orderTransactionEntity->getId(), $context);

                $this->captureService->doOpenInvoiceCapture(
                    $notificationEntity->getMerchantReference(),
                    $notificationEntity->getAmountValue(),
                    $context
                );
            } else {
                $this->orderTransactionStateHandler->paid($orderTransactionEntity->getId(), $context);
            }
        }
    }

    private function handleFailedNotification(OrderTransactionEntity $orderTransactionEntity, Context $context): void
    {
        $this->orderTransactionStateHandler->fail($orderTransactionEntity->getId(), $context);
    }
}
