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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service\Repository;

use Adyen\Shopware\Entity\PaypalPaymentAttempt\PaypalPaymentAttemptEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class PaypalPaymentAttemptRepository
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $repository;

    /**
     * @param EntityRepository $repository
     */
    public function __construct(EntityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param string $merchantReference
     * @param string $salesChannelId
     * @param string|null $pspReference
     *
     * @return void
     */
    public function create(string $merchantReference, string $salesChannelId, ?string $pspReference): void
    {
        $this->repository->create(
            [
                [
                    'id' => Uuid::randomHex(),
                    'merchantReference' => $merchantReference,
                    'salesChannelId' => $salesChannelId,
                    'pspReference' => $pspReference,
                    'status' => PaypalPaymentAttemptEntity::STATUS_OPEN,
                ]
            ],
            Context::createDefaultContext()
        );
    }

    /**
     * @param string $merchantReference
     *
     * @return PaypalPaymentAttemptEntity|null
     */
    public function getByMerchantReference(string $merchantReference): ?PaypalPaymentAttemptEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('merchantReference', $merchantReference));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * Records the outcome of a reversal. A null reversal PSP reference means the reversal failed.
     *
     * @param string $merchantReference
     * @param string|null $reversalPspReference
     *
     * @return void
     */
    public function saveReversalOutcome(string $merchantReference, ?string $reversalPspReference): void
    {
        $attempt = $this->getByMerchantReference($merchantReference);
        if (is_null($attempt)) {
            return;
        }

        $this->repository->update(
            [
                [
                    'id' => $attempt->getId(),
                    'status' => $reversalPspReference ?
                        PaypalPaymentAttemptEntity::STATUS_REVERSED :
                        PaypalPaymentAttemptEntity::STATUS_REVERSAL_FAILED,
                    'reversalPspReference' => $reversalPspReference,
                ]
            ],
            Context::createDefaultContext()
        );
    }

    /**
     * Removes the attempt once its Shopware order exists.
     *
     * @param string $merchantReference
     *
     * @return void
     */
    public function deleteByMerchantReference(string $merchantReference): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('merchantReference', $merchantReference));

        $ids = $this->repository->searchIds($criteria, Context::createDefaultContext())->getIds();
        if (empty($ids)) {
            return;
        }

        $this->repository->delete(
            array_map(static fn($id) => ['id' => $id], array_values($ids)),
            Context::createDefaultContext()
        );
    }

    /**
     * Deletes all attempts created before the given time, in batches.
     *
     * @param \DateTimeInterface $createdBefore
     * @param int $batchSize
     *
     * @return int Number of deleted attempts
     */
    public function deleteCreatedBefore(\DateTimeInterface $createdBefore, int $batchSize = 500): int
    {
        $context = Context::createDefaultContext();
        $createdBeforeUtc = \DateTimeImmutable::createFromInterface($createdBefore)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $deleted = 0;

        do {
            $criteria = new Criteria();
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::LT => $createdBeforeUtc]));
            $criteria->setLimit($batchSize);

            $ids = array_values($this->repository->searchIds($criteria, $context)->getIds());
            if (!empty($ids)) {
                $this->repository->delete(array_map(static fn($id) => ['id' => $id], $ids), $context);
                $deleted += count($ids);
            }
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
