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

use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\Repository\AdyenPaymentRepository;
use Adyen\Webhook\EventCodes;
use Adyen\Webhook\Exception\InvalidDataException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class WebhookHandlerFactory
{
    /**
     * @var LoggerInterface
     */
    private static $logger;

    /**
     * @var CaptureService
     */
    private static $captureService;

    /**
     * @var RefundService
     */
    private static $refundService;

    /**
     * @var AdyenPaymentService
     */
    private static $adyenPaymentService;

    /**
     * @var OrderTransactionStateHandler
     */
    private static $orderTransactionStateHandler;

    /**
     * @param CaptureService $captureService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param RefundService $refundService
     * @param AdyenPaymentService $adyenPaymentService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CaptureService $captureService,
        AdyenPaymentService $adyenPaymentService,
        RefundService $refundService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        LoggerInterface $logger
    ) {
        self::$captureService = $captureService;
        self::$adyenPaymentService = $adyenPaymentService;
        self::$refundService = $refundService;
        self::$orderTransactionStateHandler = $orderTransactionStateHandler;
        self::$logger = $logger;
    }

    /**
     * @param string $eventCode
     * @throws InvalidDataException
     */
    public static function create(string $eventCode)
    {
        switch ($eventCode) {
            case EventCodes::AUTHORISATION:
                $handler = new AuthorisationWebhookHandler(
                    self::$captureService,
                    self::$adyenPaymentService,
                    self::$orderTransactionStateHandler,
                    self::$logger
                );
                break;
            case EventCodes::CAPTURE:
                $handler = new CaptureWebhookHandler(
                    self::$captureService,
                    self::$orderTransactionStateHandler,
                    self::$logger
                );
                break;
            case EventCodes::CANCEL_OR_REFUND:
                $handler = new CancelOrRefundWebhookHandler(
                    self::$refundService
                );
                break;
            case EventCodes::REFUND:
                $handler = new RefundWebhookHandler(
                    self::$refundService
                );
                break;
            case EventCodes::REFUND_FAILED:
                $handler = new RefundFailedWebhookHandler(
                    self::$refundService
                );
                break;
            case EventCodes::ORDER_CLOSED:
                $handler = new OrderClosedWebhookHandler(
                    self::$adyenPaymentService,
                    self::$orderTransactionStateHandler
                );
                break;
            default:
                $errorMessage = sprintf('Notification %s is not supported by the plugin.', $eventCode);
                throw new InvalidDataException($errorMessage);
        }

        return $handler;
    }
}
