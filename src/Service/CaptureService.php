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
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

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
        'googlepay',
        'svs',
        'givex',
        'valuelink',
        'twint',
    ];

    const REASON_DELIVERY_STATE_MISMATCH = 'DELIVERY_STATE_MISMATCH';

    private OrderRepository $orderRepository;
    private OrderTransactionRepository $orderTransactionRepository;
    private AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository;
    private ConfigurationService $configurationService;
    private ClientService $clientService;

    public function __construct(
        OrderRepository $orderRepository,
        OrderTransactionRepository $orderTransactionRepository,
        AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository,
        ConfigurationService $configurationService,
        ClientService $clientService
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->adyenPaymentCaptureRepository = $adyenPaymentCaptureRepository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
    }

    /**
     * Send capture request for open invoice payments
     * @throws CaptureException
     */
    public function doOpenInvoiceCapture(string $orderNumber, $captureAmount, Context $context)
    {
        if ($this->configurationService->isManualCaptureActive()) {
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

            if (!$orderTransaction) {
                $error = 'Unable to find original authorized transaction.';
                $this->logger->error($error, ['orderNumber' => $order->getOrderNumber()]);
                throw new CaptureException($error);
            }

            $customFields = $orderTransaction->getCustomFields();
            if (empty($customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE])) {
                $error = 'Order transaction does not contain the original PSP reference.';
                $this->logger->error($error, ['orderNumber' => $order->getOrderNumber()]);
                throw new CaptureException($error);
            }
            $currencyIso = $order->getCurrency()->getIsoCode();
            try {
                $client = $this->clientService->getClient($order->getSalesChannelId());
            } catch (AdyenException|\Exception $e) {
                throw new CaptureException('Capture service not able to retrieve Client.', 0, $e);
            }

            $deliveries = $order->getDeliveries();

            $results = [];
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
                        $order->getSalesChannelId(),
                        $additionalData
                    );

                    $response = $this->sendCaptureRequest($client, $request);
                    if ('[capture-received]' === $response['response']) {
                        $this->saveCaptureRequest(
                            $orderTransaction,
                            $response['pspReference'],
                            PaymentCaptureEntity::SOURCE_SHOPWARE,
                            PaymentCaptureEntity::STATUS_PENDING_WEBHOOK,
                            intval($captureAmount),
                            $context
                        );
                    }
                    $results[] = $response;
                } else {
                    $exception = new CaptureException('Order delivery status does not match configuration');
                    $exception->reason = self::REASON_DELIVERY_STATE_MISMATCH;
                    throw $exception;
                }
            }
            $this->logger->info('Capture for order_number ' . $order->getOrderNumber() . ' end.');

            return $results;
        } else {
            throw new CaptureException('Manual capture disabled.');
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
        $lineIndex = 0;
        foreach ($lineItems as $lineItem) {
            // Skip non-product line items.
            if (empty($lineItem->getProductId()) || $lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $key = 'openinvoicedata.line' . ++$lineIndex;
            $lineItemsArray[$key . '.itemAmount'] = ceil($lineItem->getPrice()->getTotalPrice() * 100);
            $lineItemsArray[$key . '.itemVatPercentage'] = $lineItem->getPrice()->getTaxRules()
                    ->highestRate()->getPercentage() * 10;
            $lineItemsArray[$key . '.description'] = $lineItem->getLabel();
            $lineItemsArray[$key . '.itemVatAmount'] = $lineItem->getPrice()->getCalculatedTaxes()->getAmount() * 100;
            $lineItemsArray[$key . '.currencyCode'] = $currencyCode;
            $lineItemsArray[$key . '.numberOfItems'] = $lineItem->getQuantity();
        }
        $lineItemsArray['openinvoicedata.numberOfLines'] = $lineIndex;
        return $lineItemsArray;
    }

    private function buildCaptureRequest(
        string $originalReference,
        $captureAmountInMinorUnits,
        string $currency,
        string $salesChannelId,
        ?array $additionalData = null
    ): array {
        return [
            'originalReference' => $originalReference,
            'modificationAmount' => [
                'value' => $captureAmountInMinorUnits,
                'currency' => $currency,
            ],
            'merchantAccount' => $this->configurationService->getMerchantAccount($salesChannelId),
            'additionalData' => $additionalData
        ];
    }

    /**
     * @throws CaptureException
     */
    private function sendCaptureRequest(
        Client $client,
        array $request
    ) {
        try {
            $modification = new Modification($client);
            $response = $modification->capture($request);
        } catch (AdyenException $e) {
            $this->logger->error('Capture failed', $request);
            throw new CaptureException(
                'Capture failed.',
                0,
                $e
            );
        }

        return $response;
    }

    public function requiresManualCapture($handlerIdentifier)
    {
        return $this->configurationService->isManualCaptureActive() && $handlerIdentifier::$isOpenInvoice;
    }

    public function saveCaptureRequest(
        OrderTransactionEntity $orderTransaction,
        string $pspReference,
        string $source,
        string $status,
        int $captureAmount,
        Context $context
    ) {
        $this->validateNewStatus($status, $pspReference);

        $this->adyenPaymentCaptureRepository->getRepository()->create([
            [
                'orderTransactionId' => $orderTransaction->getId(),
                'pspReference' => $pspReference,
                'source' => $source,
                'status' => $status,
                'amount' => $captureAmount
            ]
        ], $context);
    }

    public function updateCaptureRequestStatus(PaymentCaptureEntity $captureEntity, string $newStatus, Context $context)
    {
        $this->validateNewStatus($newStatus, $captureEntity->getPspReference());

        $this->adyenPaymentCaptureRepository->getRepository()->update([
            [
                'id' => $captureEntity->getId(),
                'status' => $newStatus
            ]
        ], $context);
    }

    public function handleCaptureNotification(
        OrderTransactionEntity $transaction,
        NotificationEntity $notification,
        string $newStatus,
        Context $context
    ) {
        $criteria = new Criteria();

        $criteria->addFilter(new AndFilter([
            new EqualsFilter('orderTransactionId', $transaction->getId()),
            new EqualsFilter('pspReference', $notification->getPspreference())
        ]));

        /** @var PaymentCaptureEntity $adyenRefund */
        $capture = $this->adyenPaymentCaptureRepository->getRepository()
            ->search($criteria, $context)->first();

        if (is_null($capture)) {
            $this->saveCaptureRequest(
                $transaction,
                $notification->getPspreference(),
                PaymentCaptureEntity::SOURCE_ADYEN,
                $newStatus,
                intval($notification->getAmountValue()),
                $context
            );
        } else {
            $this->updateCaptureRequestStatus($capture, $newStatus, $context);
        }
    }

    /**
     * @param string $newStatus
     * @param string $pspReference
     * @throws AdyenException
     */
    private function validateNewStatus(string $newStatus, string $pspReference): void
    {
        if (!in_array($newStatus, PaymentCaptureEntity::getStatuses())) {
            $message = 'Invalid update status for payment capture entity.';
            $this->logger->error($message, ['newStatus' => $newStatus, 'pspReference' => $pspReference]);
            throw new AdyenException($message);
        }
    }
}
