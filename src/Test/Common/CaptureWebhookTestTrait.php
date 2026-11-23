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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Common;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Entities shared by the capture related webhook tests: one order of 19.99 EUR with one Adyen transaction.
 */
trait CaptureWebhookTestTrait
{
    protected function createOrderTransaction(string $state): OrderTransactionEntity
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-eur');
        $currency->setIsoCode('EUR');

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $stateMachineState = new StateMachineStateEntity();
        $stateMachineState->setId('state-' . $state);
        $stateMachineState->setTechnicalName($state);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $transaction->setOrderId('order-1');
        $transaction->setPaymentMethodId('pm-cards');
        $transaction->setOrder($order);
        $transaction->setStateMachineState($stateMachineState);
        $transaction->setAmount(
            new CalculatedPrice(19.99, 19.99, new CalculatedTaxCollection(), new TaxRuleCollection())
        );
        $transaction->setCustomFields([PaymentResponseHandler::ORIGINAL_PSP_REFERENCE => 'PSP123']);

        return $transaction;
    }

    protected function createCaptureNotification(
        string $eventCode,
        string $paymentMethod = 'visa',
        ?string $merchantOrderReference = null
    ): NotificationEntity {
        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode($eventCode);
        $notification->setSuccess(true);
        $notification->setPspreference('PSP123');
        $notification->setMerchantReference('10001');
        $notification->setAmountValue('1999');
        $notification->setAmountCurrency('EUR');
        $notification->setPaymentMethod($paymentMethod);
        $notification->setAdditionalData(
            json_encode($merchantOrderReference === null ? [] : ['merchantOrderReference' => $merchantOrderReference])
        );
        $notification->setErrorCount(0);

        return $notification;
    }
}
