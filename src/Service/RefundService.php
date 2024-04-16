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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */
namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Service\Modification;
use Adyen\Shopware\Entity\AdyenPayment\AdyenPaymentEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

class RefundService
{
    const REFUND_STRATEGY_FIRST_PAYMENT_FIRST = 'fifo';
    const REFUND_STRATEGY_LAST_PAYMENT_FIRST = 'filo';
    const REFUND_STRATEGY_RATIO = 'ratio';

    const REFUNDABLE_STATES = [
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_PARTIALLY_PAID,
    ];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var ClientService
     */
    private ClientService $clientService;

    /**
     * @var AdyenRefundRepository
     */
    private AdyenRefundRepository $adyenRefundRepository;

    /**
     * @var Currency
     */
    private Currency $currency;

    /**
     * @var OrderTransactionRepository
     */
    private OrderTransactionRepository $transactionRepository;

    /**
     * @var OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler $transactionStateHandler;

    /**
     * @var AdyenPaymentService
     */
    private AdyenPaymentService $adyenPaymentService;

    /**
     * RefundService constructor.
     *
     * @param LoggerInterface $logger
     * @param ConfigurationService $configurationService
     * @param ClientService $clientService
     * @param AdyenRefundRepository $adyenRefundRepository
     * @param Currency $currency
     * @param OrderTransactionRepository $transactionRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param AdyenPaymentService $adyenPaymentService
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigurationService $configurationService,
        ClientService $clientService,
        AdyenRefundRepository $adyenRefundRepository,
        Currency $currency,
        OrderTransactionRepository $transactionRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        AdyenPaymentService $adyenPaymentService
    ) {
        $this->logger = $logger;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->adyenRefundRepository = $adyenRefundRepository;
        $this->currency = $currency;
        $this->transactionRepository = $transactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->adyenPaymentService = $adyenPaymentService;
    }

    /**
     * Process a refund on the Adyen platform
     *
     * @param OrderEntity $order
     * @param int $refundAmount
     * @return void
     * @throws AdyenException
     */
    public function refund(OrderEntity $order, int $refundAmount): void
    {
        $merchantAccount = $this->configurationService->getMerchantAccount($order->getSalesChannelId());
        if (!$merchantAccount) {
            $message = 'No Merchant Account set. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.';
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        $refundStrategy = $this->configurationService->getRefundStrategyForGiftcards($order->getSalesChannelId());
        $sortAdyenPayments = ($refundStrategy === self::REFUND_STRATEGY_FIRST_PAYMENT_FIRST) ?
            FieldSorting::ASCENDING :
            FieldSorting::DESCENDING;

        $adyenPayments = $this->adyenPaymentService->getAdyenPayments($order->getId(), $sortAdyenPayments);

        if (empty($adyenPayments)) {
            $message = 'There is no authorised Adyen payments for this order!';
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        // TODO:: Do we need to check whether if it's a gift card payment or not?
        // TODO:: Do we need to wait for the full amount to be authorised?

        $refundRequests = [];

        if (in_array(
            $refundStrategy,
            [self::REFUND_STRATEGY_FIRST_PAYMENT_FIRST, self::REFUND_STRATEGY_LAST_PAYMENT_FIRST])
        ) {
            /** @var AdyenPaymentEntity $adyenPayment */
            foreach ($adyenPayments as $adyenPayment) {
                $refundableAmount = $adyenPayment->getAmountValue() - $adyenPayment->getTotalRefunded();
                $requestRefundAmount = min([$refundableAmount, $refundAmount]);

                $refundRequests[] = [
                    'originalReference' => $adyenPayment->getOriginalReference(),
                    'modificationAmount' => [
                        'value' => $requestRefundAmount,
                        'currency' => $order->getCurrency()->getIsoCode()
                    ],
                    'merchantAccount' => $merchantAccount
                ];

                $refundAmount = $refundableAmount - $requestRefundAmount;
                if ($refundAmount === 0) {
                    break;
                }
            }
        } elseif ($refundStrategy === self::REFUND_STRATEGY_RATIO) {

        } else {
            $message = 'Refund strategy for gift cards has not been configured correctly!';
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        // TODO:: Add idempotency key to the request

        if (!empty($refundRequests)) {
            foreach ($refundRequests as $refundRequest) {
                try {
                    $modificationService = new Modification(
                        $this->clientService->getClient($order->getSalesChannelId())
                    );

                    $this->clientService->logRequest(
                        $refundRequest,
                        Client::API_PAYMENT_VERSION,
                        '/pal/servlet/Payment/{version}/refund',
                        $order->getSalesChannelId()
                    );

                    $response = $modificationService->refund($refundRequest);

                    $this->clientService->logResponse(
                        $response,
                        $order->getSalesChannelId()
                    );

                    // If response does not contain pspReference
                    if (!array_key_exists('pspReference', $response)) {
                        $message = sprintf('Invalid response for refund on order %s', $order->getOrderNumber());
                        throw new AdyenException($message);
                    }

                    $orderTransaction = $this->getAdyenOrderTransactionForRefund($order, RefundService::REFUNDABLE_STATES);
                    $adyenRefund = $this->adyenRefundRepository
                        ->getRefundForOrderByPspReference($orderTransaction->getId(), $response['pspReference']);

                    if (is_null($adyenRefund)) {
                        $this->insertAdyenRefund(
                            $order,
                            $response['pspReference'],
                            RefundEntity::SOURCE_SHOPWARE,
                            RefundEntity::STATUS_PENDING_WEBHOOK,
                            $refundRequest['modificationAmount']['amount']
                        );
                    }
                } catch (AdyenException $e) {
                    $this->logger->error($e->getMessage());
                    throw $e;
                }
            }
        } else {
            $message = 'There is refundable payment for this order!';
            $this->logger->error($message);
            throw new AdyenException($message);
        }
    }

    /**
     * Handle refund notification, by either creating a new adyen_refund entry OR
     * updating the status of an existing adyen_refund which was initiated on shopware
     *
     * @param OrderEntity $order
     * @param NotificationEntity $notification
     * @param string $newStatus
     * @throws AdyenException
     */
    public function handleRefundNotification(OrderEntity $order, NotificationEntity $notification, string $newStatus)
    {
        $statesToSearch = self::REFUNDABLE_STATES;
        // This is included for edge cases where an already processed refund, failed (REFUND_FAILED notification)
        $statesToSearch[] = OrderTransactionStates::STATE_REFUNDED;
        $orderTransaction = $this->getAdyenOrderTransactionForRefund($order, $statesToSearch);

        $adyenRefund = $this->adyenRefundRepository
            ->getRefundForOrderByPspReference($orderTransaction->getId(), $notification->getPspreference());

        if (is_null($adyenRefund)) {
            $this->insertAdyenRefund(
                $order,
                $notification->getPspreference(),
                RefundEntity::SOURCE_ADYEN,
                $newStatus,
                intval($notification->getAmountValue())
            );
        } else {
            $this->updateAdyenRefundStatus($adyenRefund, $newStatus);
        }
    }

    /**
     * @param OrderEntity $order
     * @param string $pspReference
     * @param string $source
     * @param string $status
     * @throws AdyenException
     */
    public function insertAdyenRefund(
        OrderEntity $order,
        string $pspReference,
        string $source,
        string $status,
        int $refundAmount
    ) : void {
        $orderTransaction = $this->getAdyenOrderTransactionForRefund($order, self::REFUNDABLE_STATES);

        $this->adyenRefundRepository->getRepository()->create([
            [
                'orderTransactionId' => $orderTransaction->getId(),
                'pspReference' => $pspReference,
                'source' => $source,
                'status' => $status,
                'amount' => $refundAmount
            ]
        ], Context::createDefaultContext());
    }

    /**
     * Update the status of an already existing adyen_refund entry
     *
     * @param RefundEntity $refund
     * @param $status
     * @throws AdyenException
     */
    public function updateAdyenRefundStatus(RefundEntity $refund, $status)
    {
        if (!in_array($status, RefundEntity::getStatuses())) {
            $message = sprintf('Adyen refund to be updated to invalid status %s', $status);
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        $this->adyenRefundRepository->getRepository()->update([
            [
                'id' => $refund->getId(),
                'status' => $status
            ]
        ], Context::createDefaultContext());
    }

    /**
     * Check if amount is refundable
     *
     * @param OrderEntity $order
     * @param int $amount
     * @return bool
     */
    public function isAmountRefundable(OrderEntity $order, int $amount): bool
    {
        $refundedAmount = 0;
        $refunds = $this->adyenRefundRepository->getRefundsByOrderId($order->getId());
        /** @var RefundEntity $refund */
        foreach ($refunds->getElements() as $refund) {
            if ($refund->getStatus() !== RefundEntity::STATUS_FAILED) {
                $refundedAmount += $refund->getAmount();
            }
        }

        // Pass null to sanitize since 2 decimal places will always be used
        if ($refundedAmount + $amount > $this->currency->sanitize($order->getAmountTotal(), null)) {
            return false;
        }

        return true;
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @param string $transitionState
     * @param Context $context
     */
    public function doRefund(OrderTransactionEntity $orderTransaction, string $transitionState, Context $context)
    {
        try {
            if (OrderTransactionStates::STATE_PARTIALLY_REFUNDED === $transitionState) {
                $this->transactionStateHandler->refundPartially($orderTransaction->getId(), $context);
            } else {
                $this->transactionStateHandler->refund($orderTransaction->getId(), $context);
            }
        } catch (IllegalTransitionException $exception) {
            // set to paid, and then try again
            $this->logger->info(
                'Transaction ' . $orderTransaction->getId() . ' is '
                . $orderTransaction->getStateMachineState()->getTechnicalName() . ' and could not be set to '
                . $transitionState . ', setting to paid and then retrying.'
            );
            $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
            $this->doRefund($orderTransaction, $transitionState, $context);
        }
    }

    /**
     * Get the first adyen refundable orderTransaction based on the states and check that it has a PSP reference
     *
     * @param OrderEntity $order
     * @param array $states
     * @return OrderTransactionEntity
     * @throws AdyenException
     */
    public function getAdyenOrderTransactionForRefund(OrderEntity $order, array $states): OrderTransactionEntity
    {
        $orderTransaction = $this->transactionRepository->getFirstAdyenOrderTransactionByStates(
            $order->getId(),
            $states
        );

        if (is_null($orderTransaction)) {
            $message = sprintf(
                'Order %s has no linked transactions with states: %s',
                $order->getOrderNumber(),
                implode(', ', $states)
            );
            $this->logger->error($message);
            throw new AdyenException($message);
        } elseif (is_null($orderTransaction->getCustomFields()) ||
            !array_key_exists(PaymentResponseHandler::ORIGINAL_PSP_REFERENCE, $orderTransaction->getCustomFields())
        ) {
            $message = sprintf('Order %s has no linked psp reference', $order->getOrderNumber());
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        return $orderTransaction;
    }
}
