<?php

namespace Adyen\Shopware\Service\Repository;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class SalesChannelRepository
{
    /**
     * @var EntityRepositoryInterface
     */
    private $domainRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    /**
     * SalesChannelRepository constructor.
     * @param EntityRepositoryInterface $domainRepository
     * @param EntityRepositoryInterface $salesChannelRepository
     */
    public function __construct(
        EntityRepositoryInterface $domainRepository,
        EntityRepositoryInterface $salesChannelRepository
    ) {
        $this->domainRepository = $domainRepository;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    /**
     * @param SalesChannelContext $context
     * @return string
     */
    public function getCurrentDomainUrl(SalesChannelContext $context): string
    {
        $criteria = new Criteria();

        $domainId = $context->getSalesChannel()->getHreflangDefaultDomainId() ?: $context->getDomainId();
        $criteria->addFilter(new EqualsFilter('id', $domainId));

        $domainEntity = $this->domainRepository
            ->search($criteria, $context->getContext())
            ->first();

        if (!$domainEntity) {
            throw new SalesChannelDomainNotFoundException($context->getSalesChannel());
        }

        return $domainEntity->getUrl();
    }

    /**
     * @param SalesChannelContext $context
     * @return SalesChannelEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getSalesChannelAssocLocale(SalesChannelContext $context): SalesChannelEntity
    {
        $salesChannelCriteria = new Criteria([$context->getSalesChannel()->getId()]);

        return $this->salesChannelRepository->search(
            $salesChannelCriteria->addAssociation('language.locale'),
            $context->getContext()
        )->first();
    }
}
