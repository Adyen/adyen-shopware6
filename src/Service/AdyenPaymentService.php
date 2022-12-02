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
use Adyen\Util\Currency;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;

class AdyenPaymentService
{
    protected AdyenPaymentRepository $adyenPaymentRepository;

    const MANUAL_CAPTURE = 'manual_capture';
    const AUTO_CAPTURE = 'auto_capture';

    public function __construct(
        AdyenPaymentRepository $adyenPaymentRepository
    ) {
        $this->adyenPaymentRepository = $adyenPaymentRepository;
    }

    public function insertAdyenPayment(
        NotificationEntity $notification,
        OrderTransactionEntity $orderTransaction,
        bool $isManualCapture
    ): void {
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
            'captureMode' => $isManualCapture ? self::MANUAL_CAPTURE : self::AUTO_CAPTURE
        );

        $this->adyenPaymentRepository->getRepository()->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    public function isFullAmountAuthorized(
        string $merchantOrderReference,
        OrderTransactionEntity $orderTransactionEntity
    ): bool {
        $amountSum = 0;
        $adyenPaymentOrders = $this->adyenPaymentRepository
            ->getAdyenPaymentsByMerchantOrderReference($merchantOrderReference);

        foreach ($adyenPaymentOrders as $adyenPaymentOrder) {
            $amountSum += $adyenPaymentOrder->getAmountValue();
        }

        $currencyUtil = new Currency();
        $totalPrice = $orderTransactionEntity->getAmount()->getTotalPrice();
        $isoCode = $orderTransactionEntity->getOrder()->getCurrency()->getIsoCode();
        $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

        if ($amountSum >= $transactionAmount) {
            return true;
        }

        return false;
    }
}
