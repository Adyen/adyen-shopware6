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
use Adyen\Service\Modification;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

class RefundService
{
    const REFUNDABLE_STATES = [
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_PARTIALLY_PAID,
    ];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var AdyenRefundRepository
     */
    private $adyenRefundRepository;

    /**
     * @var Currency
     */
    private $currency;

    /** @var OrderTransactionRepository */
    private $transactionRepository;

    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

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
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigurationService $configurationService,
        ClientService $clientService,
        AdyenRefundRepository $adyenRefundRepository,
        Currency $currency,
        OrderTransactionRepository $transactionRepository,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->logger = $logger;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->adyenRefundRepository = $adyenRefundRepository;
        $this->currency = $currency;
        $this->transactionRepository = $transactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
    }

    /**
     * Process a refund on the Adyen platform
     *
     * @param OrderEntity $order
     * @return array
     * @throws AdyenException
     */
    public function refund(OrderEntity $order): array
    {
        $orderTransaction = $this->getAdyenOrderTransactionForRefund($order, self::REFUNDABLE_STATES);

        // No param since sales channel is not available since we're in admin
        $merchantAccount = $this->configurationService->getMerchantAccount();

        if (!$merchantAccount) {
            $message = 'No Merchant Account set. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.';
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        $pspReference = $orderTransaction->getCustomFields()[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE];
        $currencyIso = $order->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize($order->getAmountTotal(), $currencyIso);

        $params = [
            'originalReference' => $pspReference,
            'modificationAmount' => [
                'value' => $amount,
                'currency' => $currencyIso
            ],
            'merchantAccount' => $merchantAccount
        ];

        try {
            $modificationService = new Modification(
                $this->clientService->getClient($order->getSalesChannelId())
            );

            return $modificationService->refund($params);
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
            throw $e;
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

        $criteria = new Criteria();
        // Filtering with pspReference since in the future, multiple refunds are possible
        /** @var RefundEntity $adyenRefund */
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('orderTransactionId', $orderTransaction->getId()),
            new EqualsFilter('pspReference', $notification->getPspreference())
        ]));

        /** @var RefundEntity $adyenRefund */
        $adyenRefund = $this->adyenRefundRepository->getRepository()
            ->search($criteria, Context::createDefaultContext())->first();

        if (is_null($adyenRefund)) {
            $this->insertAdyenRefund(
                $order,
                $notification->getPspreference(),
                RefundEntity::SOURCE_ADYEN,
                $newStatus
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
        string $status
    ) : void {
        $orderTransaction = $this->getAdyenOrderTransactionForRefund($order, self::REFUNDABLE_STATES);
        $currencyIso = $order->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize($order->getAmountTotal(), $currencyIso);

        $this->adyenRefundRepository->getRepository()->create([
            [
                'orderTransactionId' => $orderTransaction->getId(),
                'pspReference' => $pspReference,
                'source' => $source,
                'status' => $status,
                'amount' => $amount
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
    private function getAdyenOrderTransactionForRefund(OrderEntity $order, array $states): OrderTransactionEntity
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
