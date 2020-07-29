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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PaymentResponseService
{
    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    public function __construct(
        EntityRepositoryInterface $repository,
        EntityRepositoryInterface $orderRepository
    ) {
        $this->repository = $repository;
        $this->orderRepository = $orderRepository;
    }

    public function getWithOrderNumber(string $orderNumber):? PaymentResponseEntity
    {
        return $this->repository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('orderNumber', $orderNumber)),
                Context::createDefaultContext()
            )
            ->first();
    }

    public function insertPaymentResponse(
        array $paymentResponse,
        string $orderNumber,
        string $salesChannelContextToken
    ): void {
        if (empty($paymentResponse)) {
            //TODO log error
            exit;
        }

        $storedPaymentResponse = $this->getWithOrderNumber($orderNumber);
        if ($storedPaymentResponse) {
            $fields['id'] = $storedPaymentResponse->getId();
        }

        $fields['token'] = $salesChannelContextToken;
        $fields['resultCode'] = $paymentResponse["resultCode"];
        $fields['orderNumber'] = $orderNumber;
        $fields['response'] = json_encode($paymentResponse);

        $this->repository->upsert(
            [$fields],
            Context::createDefaultContext()
        );
    }

    public function getWithSalesChannelApiContextToken(string $salesChannelApiContextToken) : PaymentResponseEntity
    {
        return $this->repository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('sales_channel_api_context_token', $salesChannelApiContextToken)),
                Context::createDefaultContext()
            )
            ->first();
    }
}
