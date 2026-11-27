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

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

/**
 * Only an already processed, refused AUTHORISATION of a payment of the order proves that the payment of a failed
 * transaction was refused.
 */
class NotificationServiceFailedAuthorisationTest extends AdyenTestCase
{
    private ?Criteria $criteria = null;

    public function testFindsAProcessedRefusedAuthorisationOfThePayments(): void
    {
        $this->assertTrue($this->createService(1)->hasProcessedFailedAuthorisation('10001', ['PSP_A', null, '']));

        $filters = [];
        foreach ($this->criteria->getFilters() as $filter) {
            $filters[$filter->getField()] = $filter instanceof EqualsAnyFilter
                ? $filter->getValue()
                : ($filter instanceof EqualsFilter ? $filter->getValue() : null);
        }

        $this->assertSame([
            'merchantReference' => '10001',
            'pspreference' => ['PSP_A'],
            'eventCode' => 'AUTHORISATION',
            'success' => false,
            'done' => true,
        ], $filters);
    }

    public function testReturnsFalseWithoutAMatchingNotification(): void
    {
        $this->assertFalse($this->createService(0)->hasProcessedFailedAuthorisation('10001', ['PSP_A']));
    }

    public function testDoesNotSearchWithoutAPaymentOfTheTransaction(): void
    {
        $this->assertFalse($this->createService(1)->hasProcessedFailedAuthorisation('10001', [null, '']));
        $this->assertNull($this->criteria);
    }

    private function createService(int $total): NotificationService
    {
        $repository = $this->getSimpleMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            function (Criteria $criteria, Context $context) use ($total) {
                $this->criteria = $criteria;
                $ids = $total > 0 ? ['notification-1' => ['primaryKey' => 'notification-1', 'data' => []]] : [];

                return new IdSearchResult($total, $ids, $criteria, $context);
            }
        );

        return new NotificationService($repository);
    }
}
