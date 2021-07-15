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
use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RefundService
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EntityRepositoryInterface
     */
    private $responseRepository;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var EntityRepositoryInterface
     */
    private $adyenRefundRepository;

    /**
     * @var Currency
     */
    private $currency;

    /**
     * RefundService constructor.
     *
     * @param LoggerInterface $logger
     * @param EntityRepositoryInterface $repository
     * @param ConfigurationService $configurationService
     * @param ClientService $clientService
     */
    public function __construct(
        LoggerInterface $logger,
        EntityRepositoryInterface $repository,
        ConfigurationService $configurationService,
        ClientService $clientService,
        EntityRepositoryInterface $adyenRefundRepository,
        Currency $currency
    ) {
        $this->logger = $logger;
        $this->responseRepository = $repository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->adyenRefundRepository = $adyenRefundRepository;
        $this->currency = $currency;
    }

    /**
     * Process a refund on the Adyen platform
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $context
     * @return array
     * @throws AdyenException
     */
    public function refund(OrderEntity $order, SalesChannelContext $context): array
    {
        $orderTransaction = $order->getTransactions()->first();
        if (is_null($orderTransaction) ||
            !array_key_exists(PaymentResponseHandler::ORIGINAL_PSP_REFERENCE, $orderTransaction->getCustomFields())
        ) {
            $message = sprintf('Order with id %s has no linked transactions OR has no linked psp reference', $order->getId());
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        $merchantAccount = $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());

        // TODO: Abstract this check functionality
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
     * This function is a copy of the one in PaymentResponseService
     * TODO: Abstract this into some repository
     *
     * @param OrderTransactionEntity $orderTransaction
     * @return PaymentResponseEntity|null
     */
    public function getWithOrderTransaction(OrderTransactionEntity $orderTransaction): ?PaymentResponseEntity
    {
        return $this->responseRepository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('orderTransactionId', $orderTransaction->getId()))
                    ->addAssociation('orderTransaction.order'),
                Context::createDefaultContext()
            )
            ->first();
    }

    /**
     * @param OrderEntity $order
     * @param string $pspReference
     * @param string $source
     * @param string $status
     * @param float $amount
     */
    public function insertAdyenRefund(
        OrderEntity $order,
        string $pspReference,
        string $source,
        string $status
    ) : void {

        $currencyIso = $order->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize($order->getAmountTotal(), $currencyIso);

        $this->adyenRefundRepository->create([
            [
                'orderId' => $order->getId(),
                'pspReference' => $pspReference,
                'source' => $source,
                'status' => $status,
                'amount' => $amount
            ]
        ], Context::createDefaultContext());
    }
}
