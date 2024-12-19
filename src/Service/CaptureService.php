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
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\PaymentCaptureRequest;
use Adyen\Model\Checkout\PaymentCaptureResponse;
use Adyen\Service\Checkout\ModificationsApi;
use Adyen\Shopware\Entity\AdyenPayment\AdyenPaymentEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class CaptureService
{
    use LoggerAwareTrait;

    const REASON_DELIVERY_STATE_MISMATCH = 'DELIVERY_STATE_MISMATCH';
    const REASON_WAITING_AUTH_WEBHOOK = 'WAITING_AUTH_WEBHOOK';

    private OrderRepository $orderRepository;
    private OrderTransactionRepository $orderTransactionRepository;
    private AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository;
    private ConfigurationService $configurationService;
    private ClientService $clientService;
    private AdyenPaymentService $adyenPaymentService;

    public function __construct(
        OrderRepository $orderRepository,
        OrderTransactionRepository $orderTransactionRepository,
        AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository,
        ConfigurationService $configurationService,
        ClientService $clientService,
        AdyenPaymentService $adyenPaymentService
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->adyenPaymentCaptureRepository = $adyenPaymentCaptureRepository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->adyenPaymentService = $adyenPaymentService;
    }

    /**
     * Send capture request for open invoice payments
     * @throws CaptureException
     */
    public function doOpenInvoiceCapture(string $orderNumber, $captureAmount, Context $context)
    {
        $order = $this->orderRepository->getOrderByOrderNumber(
            $orderNumber,
            $context,
            [
                'transactions', 'currency', 'lineItems', 'deliveries',
                'deliveries.shippingMethod', 'deliveries.stateMachineState'
            ]
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

        $paymentMethodHandler = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();

        if ($this->isManualCapture($paymentMethodHandler)) {
            $this->logger->info('Capture for order_number ' . $orderNumber . ' start.');

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
                if ($this->requiresCaptureOnShipment($paymentMethodHandler, $order->getSalesChannelId()) &&
                    $delivery->getStateMachineState()->getId() !== $this->configurationService->getOrderState()) {
                    $exception = new CaptureException('Order delivery status does not match configuration');
                    $exception->reason = self::REASON_DELIVERY_STATE_MISMATCH;

                    throw $exception;
                }

                $lineItems = $order->getLineItems();
                $lineItemsObjectArray = $this->getLineItemsObjectArray($lineItems);

                $request = $this->buildCaptureRequest(
                    $captureAmount,
                    $currencyIso,
                    $order->getSalesChannelId(),
                    $lineItemsObjectArray
                );

                $response = $this->sendCaptureRequest(
                    $client,
                    $customFields[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE],
                    $request
                );

                if ($response->getStatus() == "received") {
                    $this->saveCaptureRequest(
                        $orderTransaction,
                        $response->getPspReference(),
                        PaymentCaptureEntity::SOURCE_SHOPWARE,
                        PaymentCaptureEntity::STATUS_PENDING_WEBHOOK,
                        intval($captureAmount),
                        $context
                    );
                }

                $results[] = $response->toArray();
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

    /**
     * @param $handlerIdentifier
     * @return bool
     */
    public function isManualCapture($handlerIdentifier): bool
    {
        if ($handlerIdentifier::$isOpenInvoice) {
            if ($this->configurationService->isAutoCaptureActiveForOpenInvoices()) {
                // Open invoice payment methods can be auto capture if the merchant account is authorised.
                return false;
            } else {
                // Open invoice payment methods are manual capture by default.
                return true;
            }
        } else {
            return $this->configurationService->isManualCaptureActive() && $handlerIdentifier::$supportsManualCapture;
        }
    }

    /**
     * @param $handlerIdentifier
     * @return bool
     */
    public function requiresCaptureOnShipment($handlerIdentifier, $salesChannelId): bool
    {
        // Only open invoice payments can be captured on shipment.
        return $this->configurationService->isCaptureOnShipmentEnabled($salesChannelId) &&
            $handlerIdentifier::$isOpenInvoice;
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
     * @param OrderTransactionEntity $orderTransaction
     * @param NotificationEntity $notification
     * @return bool
     */
    public function checkRequiredAmountFullyCapturedInNotification(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ):bool {
        return $notification->getAmountValue() >= $this->getRequiredCaptureAmount($orderTransaction->getOrderId());
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @return bool
     */
    public function isRequiredAmountCaptured(OrderTransactionEntity $orderTransaction): bool
    {
        $requiredCaptureAmount = $this->getRequiredCaptureAmount($orderTransaction->getOrderId());
        $totalCapturedAmount = $this->getTotalCapturedAmount($orderTransaction);

        return $totalCapturedAmount >= $requiredCaptureAmount;
    }

    /**
     * @param string $orderId
     * @return int
     */
    public function getRequiredCaptureAmount(string $orderId): int
    {
        $adyenPayments = $this->adyenPaymentService->getAdyenPayments($orderId);
        $requiredCaptureAmount = 0;

        /** @var AdyenPaymentEntity $adyenPayment */
        foreach ($adyenPayments as $adyenPayment) {
            if ($adyenPayment->getCaptureMode() === AdyenPaymentService::MANUAL_CAPTURE) {
                $requiredCaptureAmount += $adyenPayment->getAmountValue();
            }
        }

        return $requiredCaptureAmount;
    }

    private function getLineItemsObjectArray(
        ?OrderLineItemCollection $lineItems
    ): array {
        $lineItemObjects = [];
        foreach ($lineItems as $lineItem) {
            // Skip non-product line items.
            if (!in_array($lineItem->getType(), AbstractPaymentMethodHandler::ALLOWED_LINE_ITEM_TYPES)) {
                continue;
            }

            $lineItemData = [
                'amountIncludingTax' => ceil($lineItem->getPrice()->getTotalPrice() * 100),
                'description' => $lineItem->getLabel(),
                'taxAmount' => intval($lineItem->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                'taxPercentage' => ($lineItem->getPrice()->getTaxRules()->highestRate()?->getPercentage() ?? 0) * 10,
                'quantity' => $lineItem->getQuantity(),
                'id' => $lineItem->getId()
            ];

            $lineItemObjects[] = new \Adyen\Model\Checkout\LineItem($lineItemData);
        }
        return $lineItemObjects;
    }

    private function buildCaptureRequest(
        $captureAmountInMinorUnits,
        string $currency,
        string $salesChannelId,
        $lineItems
    ): PaymentCaptureRequest {
        $amount = new Amount();
        $amount->setValue($captureAmountInMinorUnits);
        $amount->setCurrency($currency);

        $request = new PaymentCaptureRequest();
        $request->setAmount($amount);
        $request->setMerchantAccount($this->configurationService->getMerchantAccount($salesChannelId));
        $request->setLineItems($lineItems);

        return $request;
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

    /**
     * @param PaymentCaptureRequest $request
     * @param string $pspReference
     * @param array $additionalData
     * @return PaymentCaptureResponse $response
     * @throws CaptureException
     */
    private function sendCaptureRequest(
        Client $client,
        string $pspReference,
        PaymentCaptureRequest $request
    ) : PaymentCaptureResponse {

        try {
            $modification = new ModificationsApi($client);

            $response = $modification->captureAuthorisedPayment($pspReference, $request);
        } catch (AdyenException $e) {
            $this->logger->error('Capture failed', ["Error message" => $e->getMessage()]);
            throw new CaptureException(
                'Capture failed.',
                0,
                $e
            );
        }

        return $response;
    }

    private function getTotalCapturedAmount(OrderTransactionEntity $orderTransaction)
    {
        $totalCapturedAmount = 0;
        $captures = $this->adyenPaymentCaptureRepository->getCaptureRequestsByOrderId(
            $orderTransaction->getOrderId(),
            true
        );

        /** @var PaymentCaptureEntity $capture */
        foreach ($captures as $capture) {
            $totalCapturedAmount += $capture->getAmount();
        }

        return $totalCapturedAmount;
    }
}
