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

class AdyenPaymentService
{
    protected AdyenPaymentRepository $adyenPaymentRepository;

    public function __construct(
        AdyenPaymentRepository $adyenPaymentRepository
    ){
        $this->adyenPaymentRepository = $adyenPaymentRepository;
    }

    public function insertAdyenPayment(NotificationEntity $notification, OrderTransactionEntity $orderTransaction, bool $isManualCapture): void
    {
        $fields = array(
            'pspreference' => $notification->getPspreference(),
            'originalReference' => $notification->getOriginalReference() ?? null,
            'merchantReference' => $notification->getMerchantReference(),
            'merchantOrderReference' => json_decode($notification->getAdditionalData())->merchantOrderReference ?? null,
            'orderTransactionId' => $orderTransaction->getId(),
            'paymentMethod' => $notification->getPaymentMethod(),
            'amountValue' => $notification->getAmountValue(),
            'amountCurrency' => $notification->getAmountCurrency(),
            'additionalData' => $notification->getAdditionalData(),
            'captureMode' => $isManualCapture ? 'manual_capture' : 'auto_capture'
        );

        $this->adyenPaymentRepository->getRepository()->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    public function isFullAmountAuthorized(string $merchantReference, OrderTransactionEntity $orderTransaction): bool
    {
        $amountSum = 0;
        $adyenPaymentOrders = $this->adyenPaymentRepository->getAdyenPaymentsByMerchantReference($merchantReference);

        foreach ($adyenPaymentOrders as $adyenPaymentOrder) {
            $amountSum += $adyenPaymentOrder->getAmountValue();
        }
        if ($amountSum >= intval($orderTransaction->getOrder()->getAmountTotal()) * 100) {
            return true;
        }
        return false;
    }
}
