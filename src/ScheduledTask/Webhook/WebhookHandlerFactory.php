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
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Webhook\EventCodes;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class WebhookHandlerFactory
{
    use LoggerAwareTrait;

    /**
     * @var CaptureService
     */
    private static $captureService;

    /**
     * @var RefundService
     */
    private static $refundService;

    /**
     * @var OrderTransactionStateHandler
     */
    private static $orderTransactionStateHandler;

    /**
     * @param CaptureService $captureService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @return void
     */
    public function __construct(
        CaptureService $captureService,
        RefundService $refundService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        self::$captureService = $captureService;
        self::$orderTransactionStateHandler = $orderTransactionStateHandler;
        self::$refundService = $refundService;
    }

    public static function create(string $eventCode)
    {
        switch ($eventCode) {
            case EventCodes::AUTHORISATION:
                $handler = new AuthorisationWebhookHandler(
                    self::$captureService,
                    self::$orderTransactionStateHandler
                );
                break;
            case EventCodes::CAPTURE:
                $handler = new CaptureWebhookHandler(
                    self::$captureService,
                    self::$orderTransactionStateHandler
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
            case EventCodes::RECURRING_CONTRACT:
            case EventCodes::MANUAL_REVIEW_ACCEPT:
            case EventCodes::MANUAL_REVIEW_REJECT:
            case EventCodes::OFFER_CLOSED:
            case EventCodes::PENDING:
                $handler = new DefaultWebhookHandler();
                break;
            default:
                $exceptionMessage = sprintf(
                    'Unknown webhook type: %s. This type is not yet handled by the Adyen Magento plugin', $eventCode
                );

                throw new AdyenException($exceptionMessage);
        }

        return $handler;
    }
}
