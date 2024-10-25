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
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Util\Currency;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class RefundWebhookHandler implements WebhookHandlerInterface
{
    /**
     * @var RefundService
     */
    private RefundService $refundService;

    /**
     * @var AdyenPaymentService
     */
    private AdyenPaymentService $adyenPaymentService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param RefundService $refundService
     * @param AdyenPaymentService $adyenPaymentService
     * @param LoggerInterface $logger
     */
    public function __construct(
        RefundService $refundService,
        AdyenPaymentService $adyenPaymentService,
        LoggerInterface $logger
    ) {
        $this->refundService = $refundService;
        $this->adyenPaymentService = $adyenPaymentService;
        $this->logger = $logger;
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param string $state
     * @param string $currentTransactionState
     * @param Context $context
     * @return void
     * @throws AdyenException
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
            $this->handleFailedNotification($orderTransactionEntity, $notificationEntity);
        }
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @param Context $context
     * @return void
     * @throws AdyenException
     * @throws Exception
     */
    private function handleSuccessfulNotification(
        OrderTransactionEntity $orderTransactionEntity,
        NotificationEntity $notificationEntity,
        Context $context
    ): void {
        // Determine whether refund was full or partial.
        $refundedAmount = (int) $notificationEntity->getAmountValue();

        $currencyUtil = new Currency();
        $totalPrice = $orderTransactionEntity->getAmount()->getTotalPrice();
        $isoCode = $orderTransactionEntity->getOrder()->getCurrency()->getIsoCode();
        $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

        if ($refundedAmount > $transactionAmount) {
            throw new AdyenException('The refunded amount is greater than the transaction amount.');
        }

        $this->refundService->handleRefundNotification(
            $orderTransactionEntity->getOrder(),
            $notificationEntity,
            RefundEntity::STATUS_SUCCESS
        );

        try {
            $adyenPayment = $this->adyenPaymentService->getAdyenPayment(
                $notificationEntity->getPspreference()
            );

            $this->adyenPaymentService->updateTotalRefundedAmount(
                $adyenPayment,
                (int) $notificationEntity->getAmountValue()
            );
        } catch (Exception $e) {
            $this->logger->error(
                'Adyen payment entity could not be updated for the given notification!',
                ['notification' => $notificationEntity->getVars()]
            );
        }

        $transitionState = $refundedAmount < $transactionAmount
            ? OrderTransactionStates::STATE_PARTIALLY_REFUNDED
            : OrderTransactionStates::STATE_REFUNDED;

        $this->refundService->doRefund($orderTransactionEntity, $transitionState, $context);
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param NotificationEntity $notificationEntity
     * @return void
     * @throws AdyenException
     */
    private function handleFailedNotification(
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
