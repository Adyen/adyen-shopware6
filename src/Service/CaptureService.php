<?php

declare(strict_types=1);

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Service\Modification;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Handlers\KlarnaPayLaterPaymentMethodHandler;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Webhook\EventCodes;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

class CaptureService
{
    use LoggerAwareTrait;

    private OrderRepository $orderRepository;

    private ConfigurationService $configurationService;

    private ClientService $clientService;

    public function __construct(
        OrderRepository $orderRepository,
        ConfigurationService $configurationService,
        ClientService $clientService
    ) {
        $this->orderRepository = $orderRepository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
    }

    /**
     * @throws CaptureException
     */
    public function doKlarnaCapture(NotificationEntity $notification, Context $context)
    {
        if (
            $this->configurationService->isDelayedCaptureActive() &&
            $notification->getPaymentMethod() === KlarnaPayLaterPaymentMethodHandler::getPaymentMethodCode() &&
            $notification->getEventCode() === EventCodes::AUTHORISATION
        ) {
            $this->logger->info('Capture for order_number ' . $notification->getMerchantReference() . ' start.');
            $order = $this->orderRepository->getOrderByOrderNumber(
                $notification->getMerchantReference(),
                $context,
                ['transactions', 'currency', 'lineItems', 'deliveries', 'deliveries.shippingMethod']
            );

            if (is_null($order)) {
                throw new CaptureException('Order with order_number ' . $notification->getMerchantReference() . ' not found.', 1645112772);
            }
            try {
                $client = $this->clientService->getClient($order->getSalesChannelId());
            } catch (AdyenException|\Exception $e) {
                throw new CaptureException('Capture not able to retrieve Client.', 1645112503, $e);
            }

            $deliveries = $order->getDeliveries();

            foreach ($deliveries as $delivery) {
                if ($delivery->getStateMachineState()->getId() === $this->configurationService->getOrderState()) {
                    $this->sendCaptureRequest($order, $notification, $delivery, $client);
                } else {
                    throw new CaptureException('Wrong order state', 1645112363);
                }
            }
            $this->logger->info('Capture for order_number ' . $notification->getMerchantReference() . ' end.');
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
        NotificationEntity $notification
    ): array {
        $lineItemsArray = [];
        foreach ($lineItems as $lineItem) {
            $position = $lineItem->getPosition();
            $key = 'openinvoicedata.line' . $position;
            $lineItemsArray[$key . '.itemAmount'] = ceil($lineItem->getPrice()->getTotalPrice() * 100);
            $lineItemsArray[$key . '.itemVatPercentage'] = $lineItem->getPrice()->getTaxRules()->highestRate()->getPercentage() * 10;
            $lineItemsArray[$key . '.description'] = $lineItem->getLabel();
            $lineItemsArray[$key . '.itemVatAmount'] = $lineItem->getPrice()->getCalculatedTaxes()->getAmount() * 100;
            $lineItemsArray[$key . '.currencyCode'] = $notification->getAmountCurrency();
            $lineItemsArray[$key . '.numberOfItems'] = $lineItem->getQuantity();
        }
        $lineItemsArray['openinvoicedata.numberOfLines'] = count($lineItems);
        return $lineItemsArray;
    }

    private function buildCaptureRequest(
        NotificationEntity $notification,
        array $lineItemsArray,
        OrderDeliveryEntity $delivery
    ): array {
        return [
            'originalReference' => $notification->getPspReference(),
            'modificationAmount' => [
                'value' => $notification->getAmountValue(),
                'currency' => $notification->getAmountCurrency(),
            ],
            'merchantAccount' => $this->configurationService->getMerchantAccount(),
            'additionalData' => array_merge($lineItemsArray, [
                'openinvoicedata.shippingCompany' => $delivery->getShippingMethod()->getName(),
                'openinvoicedata.trackingNumber' => $delivery->getTrackingCodes(),
            ])
        ];
    }

    /**
     * @throws CaptureException
     */
    private function sendCaptureRequest(
        OrderEntity $order,
        NotificationEntity $notification,
        OrderDeliveryEntity $delivery,
        Client $client
    ): void {
        $lineItems = $order->getLineItems();
        $lineItemsArray = $this->getLineItemsArray($lineItems, $notification);

        $request = $this->buildCaptureRequest($notification, $lineItemsArray, $delivery);

        try {
            $modification = new Modification($client);
            $modification->capture($request);
        } catch (AdyenException $e) {
            throw new CaptureException('Capture for order_number ' . $notification->getMerchantReference() . ' failed.',
                1645112412, $e);
        }
    }
}
