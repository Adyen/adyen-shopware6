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

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PaymentStateDataService
{
    protected $paymentStateDataRepository;

    public function __construct(EntityRepositoryInterface $paymentStateDataRepository)
    {
        $this->paymentStateDataRepository = $paymentStateDataRepository;
    }

    public function insertPaymentStateData(string $contextToken, string $stateData): void
    {
        $fields = [];
        if (!empty($contextToken)) {
            $fields['token'] = $contextToken;
        }
        if (!empty($stateData)) {
            $fields['statedata'] = $stateData;
        }


        $this->paymentStateDataRepository->create([$fields],
            \Shopware\Core\Framework\Context::createDefaultContext()
        );
    }

    public function getPaymentStateDataFromContextToken(string $contextToken): string
    {
        $stateDataRow = $this->paymentStateDataRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('token', $contextToken)),
            Context::createDefaultContext()
        )->first();

        return $stateDataRow['statedata'];

    }
}
