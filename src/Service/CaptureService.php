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

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Service\Modification;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class CaptureService
{
    use LoggerAwareTrait;

    const SUPPORTED_PAYMENT_METHOD_CODES = [
        'cup',
        'cartebancaire',
        'visa',
        'visadankort',
        'mc',
        'uatp',
        'amex',
        'maestro',
        'maestrouk',
        'diners',
        'discover',
        'jcb',
        'laser',
        'paypal',
        'sepadirectdebit',
        'dankort',
        'elo',
        'hipercard',
        'mc_applepay',
        'visa_applepay',
        'amex_applepay',
        'discover_applepay',
        'maestro_applepay',
        'paywithgoogle',
        'svs',
        'givex',
        'valuelink',
        'twint',
    ];

    private OrderRepository $orderRepository;

    private OrderTransactionRepository $orderTransactionRepository;

    private ConfigurationService $configurationService;

    private ClientService $clientService;

    public function __construct(
        OrderRepository $orderRepository,
        OrderTransactionRepository $orderTransactionRepository,
        ConfigurationService $configurationService,
        ClientService $clientService
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
    }

    /**
     * Send capture request for open invoice payments if de
     * @throws CaptureException
     */
    public function doOpenInvoiceCapture(string $orderNumber, $captureAmount, Context $context)
    {
        if (!$this->configurationService->isManualCaptureActive()) {
            $this->logger->info('Capture for order_number ' . $orderNumber . ' start.');
            $order = $this->orderRepository->getOrderByOrderNumber(
                $orderNumber,
                $context,
                ['transactions', 'currency', 'lineItems', 'deliveries', 'deliveries.shippingMethod']
            );

            if (is_null($order)) {
                throw new CaptureException(
                    'Order with order_number ' . $orderNumber . ' not found.'
                );
            }
            $orderTransaction = $this->orderTransactionRepository
                ->getFirstAdyenOrderTransactionByStates($order->getId(), [OrderTransactionStates::STATE_AUTHORIZED]);
            $customFields = $orderTransaction->getCustomFields();
            if (empty($customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE])) {
                $error = 'Unable to find original authorized payment.';
                $this->logger->error($error, $order->getVars());
                throw new CaptureException($error);
            }
            $currencyIso = $order->getCurrency()->getIsoCode();
            try {
                $client = $this->clientService->getClient($order->getSalesChannelId());
            } catch (AdyenException|\Exception $e) {
                throw new CaptureException('Capture service not able to retrieve Client.', 0, $e);
            }

            $deliveries = $order->getDeliveries();

            foreach ($deliveries as $delivery) {
                if ($delivery->getStateMachineState()->getId() === $this->configurationService->getOrderState()) {
                    $lineItems = $order->getLineItems();
                    $lineItemsArray = $this->getLineItemsArray($lineItems, $order->getCurrency()->getIsoCode());

                    $additionalData = array_merge($lineItemsArray, [
                        'openinvoicedata.shippingCompany' => $delivery->getShippingMethod()->getName(),
                        'openinvoicedata.trackingNumber' => $delivery->getTrackingCodes(),
                    ]);

                    $request = $this->buildCaptureRequest(
                        $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE],
                        $captureAmount,
                        $currencyIso,
                        $additionalData
                    );

                    $this->sendCaptureRequest($client, $request);
                } else {
                    throw new CaptureException('Wrong order state');
                }
            }
            $this->logger->info('Capture for order_number ' . $order->getOrderNumber() . ' end.');
        }
    }

    public function getRescheduleNotificationTime(): \DateTime
    {
        $dateTime = new \DateTime();
        try {
            $rescheduleTime = $this->configurationService->getRescheduleTime();
            if (is_int($rescheduleTime)) {
                $dateTime->add(new \DateInterval('PT' . $rescheduleTime . 'S'));
            }
        } catch (\Exception $e) {
            // If DateInterval throws an exception dateTime is still set therefore no action needed
        }
        return $dateTime;
    }

    private function getLineItemsArray(
        ?OrderLineItemCollection $lineItems,
        $currencyCode
    ): array {
        $lineItemsArray = [];
        foreach ($lineItems as $lineItem) {
            $position = $lineItem->getPosition();
            $key = 'openinvoicedata.line' . $position;
            $lineItemsArray[$key . '.itemAmount'] = ceil($lineItem->getPrice()->getTotalPrice() * 100);
            $lineItemsArray[$key . '.itemVatPercentage'] = $lineItem->getPrice()->getTaxRules()
                    ->highestRate()->getPercentage() * 10;
            $lineItemsArray[$key . '.description'] = $lineItem->getLabel();
            $lineItemsArray[$key . '.itemVatAmount'] = $lineItem->getPrice()->getCalculatedTaxes()->getAmount() * 100;
            $lineItemsArray[$key . '.currencyCode'] = $currencyCode;
            $lineItemsArray[$key . '.numberOfItems'] = $lineItem->getQuantity();
        }
        $lineItemsArray['openinvoicedata.numberOfLines'] = count($lineItems);
        return $lineItemsArray;
    }

    private function buildCaptureRequest(
        string $originalReference,
        $captureAmountInMinorUnits,
        string $currency,
        ?array $additionalData = null
    ): array {
        return [
            'originalReference' => $originalReference,
            'modificationAmount' => [
                'value' => $captureAmountInMinorUnits,
                'currency' => $currency,
            ],
            'merchantAccount' => $this->configurationService->getMerchantAccount(),
            'additionalData' => $additionalData
        ];
    }

    /**
     * @throws CaptureException
     */
    private function sendCaptureRequest(
        Client $client,
        array $request
    ): void {
        try {
            $modification = new Modification($client);
            $modification->capture($request);
        } catch (AdyenException $e) {
            $this->logger->error('Capture failed', $request);
            throw new CaptureException(
                'Capture failed.',
                0,
                $e
            );
        }
    }

    public function requiresManualCapture($handlerIdentifier)
    {
        return $this->configurationService->isManualCaptureActive() &&
            ($handlerIdentifier::$isOpenInvoice || in_array($handlerIdentifier::getPaymentMethodCode(), self::SUPPORTED_PAYMENT_METHOD_CODES));
    }
}
