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
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PluginPaymentMethodsService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Webhook\EventCodes;
use Adyen\Webhook\Exception\InvalidDataException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class WebhookHandlerFactory
{
    /** @var LoggerInterface */
    private static $logger;

    /** @var CaptureService */
    private static $captureService;

    /** @var RefundService */
    private static $refundService;

    /** @var AdyenPaymentService */
    private static $adyenPaymentService;

    /** @var OrderTransactionStateHandler */
    private static $orderTransactionStateHandler;

    /** @var PluginPaymentMethodsService */
    private static $pluginPaymentMethodsService;

    /** @var ConfigurationService */
    private static $configurationService;

    /**
     * @param CaptureService $captureService
     * @param AdyenPaymentService $adyenPaymentService
     * @param RefundService $refundService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param PluginPaymentMethodsService $pluginPaymentMethodsService
     * @param ConfigurationService $configurationService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CaptureService $captureService,
        AdyenPaymentService $adyenPaymentService,
        RefundService $refundService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        PluginPaymentMethodsService $pluginPaymentMethodsService,
        ConfigurationService $configurationService,
        LoggerInterface $logger
    ) {
        self::$captureService = $captureService;
        self::$adyenPaymentService = $adyenPaymentService;
        self::$refundService = $refundService;
        self::$orderTransactionStateHandler = $orderTransactionStateHandler;
        self::$pluginPaymentMethodsService = $pluginPaymentMethodsService;
        self::$configurationService = $configurationService;
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
                    self::$pluginPaymentMethodsService,
                    self::$configurationService,
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
                    self::$refundService,
                    self::$orderTransactionStateHandler
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
                    self::$captureService,
                    self::$orderTransactionStateHandler,
                    self::$configurationService,
                    self::$logger
                );
                break;
            case EventCodes::OFFER_CLOSED:
                $handler = new OfferClosedWebhookHandler(self::$orderTransactionStateHandler);
                break;
            case EventCodes::CANCELLED:
            case EventCodes::CANCELLATION:
                $handler = new CancellationWebhookHandler(self::$orderTransactionStateHandler);
                break;
            default:
                $errorMessage = sprintf('Notification %s is not supported by the plugin.', $eventCode);
                throw new InvalidDataException($errorMessage);
        }

        return $handler;
    }
}
