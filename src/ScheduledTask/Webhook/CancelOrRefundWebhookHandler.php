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
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Util\Currency;
use Adyen\Webhook\PaymentStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class CancelOrRefundWebhookHandler implements WebhookHandlerInterface
{
    use CancellableWebhookHandlerTrait;

    /**
     * @var RefundService
     */
    private RefundService $refundService;
    /**
     * @var OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler $orderTransactionStateHandler;

    /**
     * @param RefundService $refundService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     *
     * @return void
     */
    public function __construct(
        RefundService $refundService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->refundService = $refundService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param string $state
     * @param string $currentTransactionState
     * @param Context $context
     *
     * @return void
     *
     * @throws \Adyen\AdyenException
     */
    public function handleWebhook(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        string $state,
        string $currentTransactionState,
        Context $context
    ): void {
        if ($notificationEntity->isSuccess() && $state !== $currentTransactionState) {
            // Process refund notification
            if ($state === PaymentStates::STATE_REFUNDED) {
                $this->handleSuccessfulRefund($orderTransactionEntity, $notificationEntity, $context);
            }

            $this->handleCancelWebhook($orderTransactionEntity, $this->orderTransactionStateHandler, $state, $context);
        } else {
            // If CANCEL event fails (ie. success=false), transaction is unchanged.
            // WH processor returns STATE_PAID for unsuccessful REFUND notifications.
            if ($state === PaymentStates::STATE_PAID) {
                $this->handleFailedRefundNotification($orderTransactionEntity, $notificationEntity);
            }
        }
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param Context $context
     *
     * @return void
     *
     * @throws \Adyen\AdyenException
     */
    private function handleSuccessfulRefund(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        Context $context
    ): void {
        // Determine whether refund was full or partial.
        $refundedAmount = (int)$notificationEntity->getAmountValue();

        $currencyUtil = new Currency();
        $totalPrice = $orderTransactionEntity->getAmount()->getTotalPrice();
        $isoCode = $orderTransactionEntity->getOrder()->getCurrency()->getIsoCode();
        $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

        if ($refundedAmount > $transactionAmount) {
            throw new \Exception('The refunded amount is greater than the transaction amount.');
        }

        $this->refundService->handleRefundNotification(
            $orderTransactionEntity->getOrder(),
            $notificationEntity,
            RefundEntity::STATUS_SUCCESS
        );
        $transitionState = $refundedAmount < $transactionAmount
            ? OrderTransactionStates::STATE_PARTIALLY_REFUNDED
            : OrderTransactionStates::STATE_REFUNDED;

        $this->refundService->doRefund($orderTransactionEntity, $transitionState, $context);
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     *
     * @return void
     *
     * @throws \Adyen\AdyenException
     */
    private function handleFailedRefundNotification(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity
    ): void {
        $this->refundService->handleRefundNotification(
            $orderTransactionEntity->getOrder(),
            $notificationEntity,
            RefundEntity::STATUS_FAILED
        );
    }
}
