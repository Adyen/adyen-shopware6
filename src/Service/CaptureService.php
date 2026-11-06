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
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use DateTime;
use Psr\Log\LoggerAwareTrait;
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
    /**
     * The order has no authorized Adyen transaction.
     */
    const REASON_NOTHING_TO_CAPTURE = 'NOTHING_TO_CAPTURE';

    /**
     * @var OrderRepository $orderRepository
     */
    private OrderRepository $orderRepository;
    /**
     * @var OrderTransactionRepository $orderTransactionRepository
     */
    private OrderTransactionRepository $orderTransactionRepository;
    /**
     * @var AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository
     */
    private AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository;
    /**
     * @var ConfigurationService $configurationService
     */
    private ConfigurationService $configurationService;
    /**
     * @var ClientService $clientService
     */
    private ClientService $clientService;
    /**
     * @var AdyenPaymentService $adyenPaymentService
     */
    private AdyenPaymentService $adyenPaymentService;
    /**
     * @var CaptureConfigurationResolver $captureConfigurationResolver
     */
    private CaptureConfigurationResolver $captureConfigurationResolver;

    /**
     * @param OrderRepository $orderRepository
     * @param OrderTransactionRepository $orderTransactionRepository
     * @param AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository
     * @param ConfigurationService $configurationService
     * @param ClientService $clientService
     * @param AdyenPaymentService $adyenPaymentService
     * @param CaptureConfigurationResolver $captureConfigurationResolver
     */
    public function __construct(
        OrderRepository $orderRepository,
        OrderTransactionRepository $orderTransactionRepository,
        AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository,
        ConfigurationService $configurationService,
        ClientService $clientService,
        AdyenPaymentService $adyenPaymentService,
        CaptureConfigurationResolver $captureConfigurationResolver
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->adyenPaymentCaptureRepository = $adyenPaymentCaptureRepository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->adyenPaymentService = $adyenPaymentService;
        $this->captureConfigurationResolver = $captureConfigurationResolver;
    }

    /**
     * Sends the capture request for the authorised Adyen transaction of the order. Used by capture on shipment
     * (webhooks) and by the manual capture from the administration.
     *
     * @param string $orderNumber
     * @param int|string|null $captureAmount Amount in minor units. Webhooks pass the notification amount, a nullable
     *     string.
     * @param Context $context
     * @param bool $skipDeliveryCheck True for the manual capture from the administration, which is allowed before
     *     the configured delivery state is reached.
     *
     * @return array
     *
     * @throws CaptureException
     * @throws AdyenException
     */
    public function doCapture(
        string $orderNumber,
        int|string|null $captureAmount,
        Context $context,
        bool $skipDeliveryCheck = false
    ): array {
        $captureAmount = (int)$captureAmount;

        $order = $this->orderRepository->getOrderByOrderNumber(
            $orderNumber,
            $context,
            [
                'transactions',
                'currency',
                'lineItems',
                'deliveries',
                'deliveries.shippingMethod',
                'deliveries.stateMachineState'
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
            $message = 'Unable to find original authorized transaction.';
            $this->logger->info($message, ['orderNumber' => $order->getOrderNumber()]);
            $exception = new CaptureException($message);
            $exception->reason = self::REASON_NOTHING_TO_CAPTURE;

            throw $exception;
        }

        $captureConfiguration = $this->captureConfigurationResolver->resolveForTransaction(
            $orderTransaction,
            $order->getSalesChannelId()
        );

        if ($captureConfiguration->isDeferredCapture()) {
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
                if (!$skipDeliveryCheck && $captureConfiguration->isCaptureOnShipment() &&
                    $delivery->getStateMachineState()->getId() !== $captureConfiguration->orderStateId) {
                    $exception = new CaptureException('Order delivery status does not match configuration');
                    $exception->reason = self::REASON_DELIVERY_STATE_MISMATCH;

                    throw $exception;
                }

                $lineItems = $order->getLineItems();
                $lineItemsObjectArray = $this->getLineItemsObjectArray($lineItems);

                $request = $this->buildCaptureRequest(
                    $captureAmount,
                    $orderNumber,
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
                        $captureAmount,
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

    /**
     * @param CaptureConfiguration|null $captureConfiguration The retry interval of the payment; the global retry
     *     interval if not given.
     *
     * @return DateTime
     */
    public function getRescheduleNotificationTime(?CaptureConfiguration $captureConfiguration = null): DateTime
    {
        $dateTime = new DateTime();
        try {
            $rescheduleTime = $captureConfiguration->retryIntervalSeconds
                ?? $this->configurationService->getRescheduleTime();
            if (is_int($rescheduleTime)) {
                $dateTime->add(new \DateInterval('PT' . $rescheduleTime . 'S'));
            }
        } catch (\Exception $e) {
            // If DateInterval throws an exception dateTime is still set therefore no action needed
        }
        return $dateTime;
    }

    /**
     * Capture configuration of a payment: the one stored when the payment was created, or the current configuration
     * for transactions created before it was stored (spec §5).
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param string|null $salesChannelId
     *
     * @return CaptureConfiguration
     */
    public function getCaptureConfiguration(
        OrderTransactionEntity $orderTransaction,
        ?string $salesChannelId
    ): CaptureConfiguration {
        return $this->captureConfigurationResolver->resolveForTransaction($orderTransaction, $salesChannelId);
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param string $pspReference
     * @param string $source
     * @param string $status
     * @param int $captureAmount
     * @param Context $context
     *
     * @return void
     *
     * @throws AdyenException
     */
    public function saveCaptureRequest(
        OrderTransactionEntity $orderTransaction,
        string $pspReference,
        string $source,
        string $status,
        int $captureAmount,
        Context $context
    ): void {
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

    /**
     * @param PaymentCaptureEntity $captureEntity
     * @param string $newStatus
     * @param Context $context
     *
     * @return void
     *
     * @throws AdyenException
     */
    public function updateCaptureRequestStatus(
        PaymentCaptureEntity $captureEntity,
        string $newStatus,
        Context $context
    ): void {
        $this->validateNewStatus($newStatus, $captureEntity->getPspReference());

        $this->adyenPaymentCaptureRepository->getRepository()->update([
            [
                'id' => $captureEntity->getId(),
                'status' => $newStatus
            ]
        ], $context);
    }

    /**
     * @param OrderTransactionEntity $transaction
     * @param NotificationEntity $notification
     * @param string $newStatus
     * @param Context $context
     *
     * @return void
     *
     * @throws AdyenException
     */
    public function handleCaptureNotification(
        OrderTransactionEntity $transaction,
        NotificationEntity $notification,
        string $newStatus,
        Context $context
    ): void {
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
     *
     * @return bool
     */
    public function checkRequiredAmountFullyCapturedInNotification(
        OrderTransactionEntity $orderTransaction,
        NotificationEntity $notification
    ): bool {
        return $notification->getAmountValue() >= $this->getRequiredCaptureAmount($orderTransaction->getOrderId());
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     *
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
     *
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

    /**
     * @param OrderLineItemCollection|null $lineItems
     *
     * @return array
     */
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

    /**
     * @param $captureAmountInMinorUnits
     * @param string $merchantReference
     * @param string $currency
     * @param string $salesChannelId
     * @param $lineItems
     *
     * @return PaymentCaptureRequest
     */
    private function buildCaptureRequest(
        $captureAmountInMinorUnits,
        string $merchantReference,
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
        $request->setReference($merchantReference);
        $request->setLineItems($lineItems);

        return $request;
    }

    /**
     * @param string $newStatus
     * @param string $pspReference
     *
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
     * @param Client $client
     * @param string $pspReference
     * @param PaymentCaptureRequest $request
     *
     * @return PaymentCaptureResponse $response
     * @throws CaptureException
     */
    private function sendCaptureRequest(
        Client $client,
        string $pspReference,
        PaymentCaptureRequest $request
    ): PaymentCaptureResponse {

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

    /**
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return int
     */
    private function getTotalCapturedAmount(OrderTransactionEntity $orderTransaction): int
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
