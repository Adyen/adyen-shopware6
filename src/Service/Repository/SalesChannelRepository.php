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
use Adyen\Shopware\Service\ConfigurationService;

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
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * SalesChannelRepository constructor.
     * @param EntityRepositoryInterface $domainRepository
     * @param EntityRepositoryInterface $salesChannelRepository
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        EntityRepositoryInterface $domainRepository,
        EntityRepositoryInterface $salesChannelRepository,
        ConfigurationService $configurationService
    ) {
        $this->domainRepository = $domainRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
    }

    /**
     * @param SalesChannelContext $context
     * @return string
     */
    public function getCurrentDomainUrl(SalesChannelContext $context): string
    {
        $criteria = new Criteria();
        $isDomainOverrideEnabled = $this->configurationService->getEnableOverrideDefaultDomain($context->getSalesChannelId());
        $domainUrlId = $this->configurationService->getDomainUrlId($context->getSalesChannelId());
        $domainId = $context->getSalesChannel()->getHreflangDefaultDomainId() ?: $context->getDomainId();

        if ($isDomainOverrideEnabled) {
            $criteria->addFilter(new EqualsFilter('id', $domainUrlId));
        } elseif ($domainId) {
            $criteria->addFilter(new EqualsFilter('id', $domainId));
        } else {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannel()->getId()));
            $criteria->setLimit(1);
        }

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
