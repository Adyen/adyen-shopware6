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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Shopware\Entity\PaymentStateData\PaymentStateDataEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Psr\Log\LoggerInterface;

class PaymentStateDataService
{
    /**
     * @var EntityRepository
     */
    protected $paymentStateDataRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * PaymentStateDataService constructor.
     * @param EntityRepository $paymentStateDataRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $paymentStateDataRepository,
        LoggerInterface $logger
    ) {
        $this->paymentStateDataRepository = $paymentStateDataRepository;
        $this->logger = $logger;
    }

    /**
     * @param string $contextToken
     * @param string $stateData
     * @param array $additionalData
     * @throws AdyenException
     */
    public function insertPaymentStateData(string $contextToken, string $stateData, array $additionalData = []): void
    {
        if (empty($contextToken) || empty($stateData)) {
            $message = 'No context token or state.data found, unable to save payment state.data';
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        $stateDataArray = json_decode($stateData, true);

        //Set additional data to persist along with the state.data
        $stateDataArray['additionalData'] = $additionalData;

        $fields['token'] = $contextToken;
        $fields['statedata'] = json_encode($stateDataArray);
        $stateData = $this->getPaymentStateDataFromContextToken($contextToken);

        if ($stateData) {
            $fields['id'] = $stateData->getId();
        }

        $this->paymentStateDataRepository->upsert(
            [$fields],
            Context::createDefaultContext()
        );
    }

    /**
     * @param string $contextToken
     * @return PaymentStateDataEntity|null
     */
    public function getPaymentStateDataFromContextToken(string $contextToken): ?PaymentStateDataEntity
    {
        return $this->paymentStateDataRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('token', $contextToken)),
            Context::createDefaultContext()
        )->first();
    }

    public function updateStateDataContextToken(PaymentStateDataEntity $stateData, $newToken): void
    {
        $this->paymentStateDataRepository->update([
            [
                'id' => $stateData->getId(),
                'token' => $newToken
            ]
        ], Context::createDefaultContext());
    }

    /**
     * @param PaymentStateDataEntity $stateData
     */
    public function deletePaymentStateData(string $Id): void
    {
        $this->paymentStateDataRepository->delete(
            [
                ['id' => $Id],
            ],
            Context::createDefaultContext()
        );
    }

}
