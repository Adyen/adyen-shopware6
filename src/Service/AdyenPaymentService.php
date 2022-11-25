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

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Service\Repository\AdyenPaymentRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class AdyenPaymentService
{
    protected EntityRepository $paymentRepository;
    protected AdyenPaymentRepository $adyenPaymentRepository;

    public function __construct(EntityRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;
    }

    public function insertAdyenPayment(NotificationEntity $notification, OrderTransactionEntity $orderTransaction, bool $isManualCapture): void
    {
        $fields = array(
            'pspReference' => $notification['pspReference'],
            'originalReference' => $notification['originalReference'],
            'merchantReference' => $notification['merchantReference'],
            'merchantOrderReference' => $notification['additionalData']['merchantOrderReference'] ?? null,
            'orderTransactionId' => $orderTransaction->getId(),
            'paymentMethod' => $notification['paymentMethod'],
            'amountValue' => $notification['amount']['value'],
            'amountCurrency' => $notification['amount']['currency'],
            'additionalData' => json_encode($notification['additionalData']),
            'captureMode' => $isManualCapture ? 'manual_capture' : 'auto_capture'
        );

        $this->paymentRepository->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    public function isFullAmountAuthorized($merchantReference, OrderTransactionEntity $orderTransaction): bool
    {
        // fetch all rows from adyen_payment by merchantReference -> do that in the adyen payment repository
        $adyenPaymentOrders = $this->adyenPaymentRepository->getOrdersByMerchantReference($merchantReference);
        $amountSum = 0;
        foreach ($adyenPaymentOrders as $adyenPaymentOrder) {
            // sum all the amount values
            $amountSum += $adyenPaymentOrder['amountValue'];
        }
        if ($amountSum >= $orderTransaction->getAmount()) {
            return true;
        }
        return false;
    }
}
