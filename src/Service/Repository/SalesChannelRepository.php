<?php

namespace Adyen\Shopware\Service\Repository;

use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Adyen\Shopware\Service\ConfigurationService;

class SalesChannelRepository
{
    /**
     * @var EntityRepository
     */
    private $domainRepository;

    /**
     * @var EntityRepository
     */
    private $salesChannelRepository;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var EntityRepository
     */
    private $languageRepository;

    /**
     * SalesChannelRepository constructor.
     * @param EntityRepository $domainRepository
     * @param EntityRepository $salesChannelRepository
     * @param ConfigurationService $configurationService
     * @param EntityRepository $languageRepository
     */
    public function __construct(
        EntityRepository $domainRepository,
        EntityRepository $salesChannelRepository,
        ConfigurationService $configurationService,
        EntityRepository $languageRepository
    ) {
        $this->domainRepository = $domainRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->languageRepository = $languageRepository;
    }

    /**
     * @param SalesChannelContext $context
     * @return string
     */
    public function getCurrentDomainUrl(SalesChannelContext $context): string
    {
        $criteria = new Criteria();
        $isDomainOverrideEnabled = $this->configurationService->getIsOverrideDefaultDomainEnabled(
            $context->getSalesChannelId()
        );
        $domainUrlId = $this->configurationService->getDefaultDomainId($context->getSalesChannelId());
        $domainId = $context->getDomainId() ?: $context->getSalesChannel()->getHreflangDefaultDomainId();

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
     * @param array $associations
     * @return SalesChannelEntity
     */
    public function getSalesChannelAssoc(SalesChannelContext $context, array $associations = []): SalesChannelEntity
    {
        $criteria = new Criteria([$context->getSalesChannel()->getId()]);
        foreach ($associations as $association) {
            $criteria->addAssociation($association);
        }

        return $this->salesChannelRepository->search($criteria, $context->getContext())->first();
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return string
     */
    public function getSalesChannelLocale(SalesChannelContext $salesChannelContext): string
    {
        $criteria = new Criteria();

        $criteria->addFilter(new EqualsFilter('id', $salesChannelContext->getLanguageId()));
        $criteria->addAssociation('locale');

        $languageEntity = $this->languageRepository->search($criteria, $salesChannelContext->getContext())->first();

        return $languageEntity->getLocale()->getCode();
    }
}
