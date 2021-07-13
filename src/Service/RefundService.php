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
     * @var Modification $modificationService
     */
    private $modificationService;

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
     * RefundService constructor.
     *
     * @param LoggerInterface $logger
     * @param Modification $modificationService
     * @param EntityRepositoryInterface $repository
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        LoggerInterface $logger,
        Modification $modificationService,
        EntityRepositoryInterface $repository,
        ConfigurationService $configurationService
    ) {
        $this->logger = $logger;
        $this->modificationService = $modificationService;
        $this->responseRepository = $repository;
        $this->configurationService = $configurationService;
    }

    /**
     * Process a refund on the Adyen platform
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $context
     * @return bool
     * @throws AdyenException
     */
    public function refund(OrderEntity $order, SalesChannelContext $context): bool
    {
        $orderTransaction = $order->getTransactions()->first();
        if (is_null($orderTransaction) ||
            !array_key_exists(PaymentResponseHandler::PSP_REFERENCE, $orderTransaction->getCustomFields())
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

        $pspReference = $orderTransaction->getCustomFields()[PaymentResponseHandler::PSP_REFERENCE];

        $params = [
            'originalReference' => $pspReference,
            'modificationAmount' => array(
                'value' => $order->getAmountTotal(),
                'currency' => $order->getCurrency()
            ),
            'merchantAccount' => $merchantAccount
        ];

        try {
            return $this->modificationService->refund($params);
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
}
