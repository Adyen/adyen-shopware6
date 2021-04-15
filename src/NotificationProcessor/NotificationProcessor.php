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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\NotificationProcessor;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Util\Currency;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;

abstract class NotificationProcessor
{
    use LoggerAwareTrait;

    /**
     * @var Currency
     */
    protected $currencyUtil;

    public function __construct()
    {
        $this->currencyUtil = new Currency();
    }

    /**
     * @var OrderEntity
     */
    protected $order;
    /**
     * @var NotificationEntity
     */
    protected $notification;
    /**
     * @var OrderTransactionStateHandler
     */
    protected $transactionStateHandler;

    /**
     * @return OrderEntity
     */
    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    /**
     * @param OrderEntity $order
     */
    public function setOrder(OrderEntity $order): void
    {
        $this->order = $order;
    }

    /**
     * @return NotificationEntity
     */
    public function getNotification(): NotificationEntity
    {
        return $this->notification;
    }

    /**
     * @param NotificationEntity $notification
     */
    public function setNotification(NotificationEntity $notification): void
    {
        $this->notification = $notification;
    }

    /**
     * @return OrderTransactionStateHandler
     */
    public function getTransactionStateHandler(): OrderTransactionStateHandler
    {
        return $this->transactionStateHandler;
    }

    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     */
    public function setTransactionStateHandler(OrderTransactionStateHandler $transactionStateHandler): void
    {
        $this->transactionStateHandler = $transactionStateHandler;
    }
}
