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

use Adyen\Shopware\Entity\PaymentStateData\PaymentStateDataEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Psr\Log\LoggerInterface;

class PaymentStateDataService
{
    /**
     * @var EntityRepositoryInterface
     */
    protected $paymentStateDataRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * PaymentStateDataService constructor.
     * @param EntityRepositoryInterface $paymentStateDataRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepositoryInterface $paymentStateDataRepository,
        LoggerInterface $logger
    ) {
        $this->paymentStateDataRepository = $paymentStateDataRepository;
        $this->logger = $logger;
    }

    /**
     * @param string $contextToken
     * @param string $stateData
     * @param string $origin
     */
    public function insertPaymentStateData(string $contextToken, string $stateData, string $origin): void
    {

        if (empty($contextToken) || empty($stateData)) {
            $this->logger->error('No context token or state.data found, unable to save payment state.data');
            exit;
        }

        $stateDataArray = json_decode($stateData, true);

        //Set additional data to persist along with the state.data
        $additionalData = [
            'origin' => $origin
        ];
        $stateDataArray['additionalData'] = $additionalData;

        $fields['token'] = $contextToken;
        $fields['statedata'] = json_encode($stateDataArray);
        $stateData = $this->getPaymentStateDataFromContextToken($contextToken);

        if ($stateData) {
            $fields['id'] = $stateData->getId();
        }

        $this->paymentStateDataRepository->upsert(
            [$fields],
            \Shopware\Core\Framework\Context::createDefaultContext()
        );
    }

    /**
     * @param string $contextToken
     * @return string
     */
    public function getPaymentStateDataFromContextToken(string $contextToken): ?PaymentStateDataEntity
    {
        $stateDataRow = $this->paymentStateDataRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('token', $contextToken)),
            Context::createDefaultContext()
        )->first();

        return $stateDataRow;
    }

    /**
     * @param string $contextToken
     * @return string|null
     */
    public function getPaymentMethodType(string $contextToken): ?string
    {
        $stateData = $this->getPaymentStateDataFromContextToken($contextToken);
        if (empty($stateData)) {
            return null;
        }

        $stateDataArray = json_decode($stateData->getStateData(), true);
        if (empty($stateDataArray['paymentMethod']['type'])) {
            return null;
        }

        return $stateDataArray['paymentMethod']['type'];

    /**
     * @param PaymentStateDataEntity $stateData
     */
    public function deletePaymentStateData(PaymentStateDataEntity $stateData): void
    {
        $this->paymentStateDataRepository->delete(
            [
                ['id' => $stateData->getId()],
            ],
            Context::createDefaultContext()
        );
    }
}
