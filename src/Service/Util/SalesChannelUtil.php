<?php

namespace Adyen\Shopware\Service\Util;

use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class SalesChannelUtil
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
     * SalesChannelUtil constructor.
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
    public function getSalesChannelUrl(SalesChannelContext $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannel()->getId()));
        $criteria->setLimit(1);

        $domainEntity = $this->domainRepository
            ->search($criteria, $context->getContext())
            ->first();

        if (!$domainEntity) {
            throw new SalesChannelDomainNotFoundException($context->getSalesChannel());
        }

        $url = $domainEntity->getUrl();

        return $url;
    }

    /**
     * @param SalesChannelContext $context
     * @return SalesChannelEntity
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function getSalesChannelAssocLocale(SalesChannelContext $context): SalesChannelEntity
    {
        $salesChannelCriteria = new Criteria([$context->getSalesChannel()->getId()]);

        return $this->salesChannelRepository->search(
            $salesChannelCriteria->addAssociation('language.locale'),
            Context::createDefaultContext())->first();
    }
}
