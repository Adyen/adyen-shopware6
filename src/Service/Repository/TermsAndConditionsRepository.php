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

namespace Adyen\Shopware\Service\Repository;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class TermsAndConditionsRepository
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $categoryRepository;

    /**
     * @var TermsAndConditionsRepository
     */
    private EntityRepository $seoUrlRepository;

    public function __construct(
        EntityRepository $categoryRepository,
        EntityRepository $seoUrlRepository
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->seoUrlRepository = $seoUrlRepository;
    }

    /**
     * Retrieves the relative SEO URL for the Terms and Conditions page.
     *
     * @param string|null $tosPageId The CMS page ID of the Terms and Conditions.
     * @param Context $context The Shopware context.
     * @return string|null The relative SEO URL or null if not found.
     */
    public function getTermsAndConditionsPath(?string $tosPageId, Context $context): ?string
    {
        if (!$tosPageId) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('cmsPageId', $tosPageId));

        $category = $this->categoryRepository->search($criteria, $context)->first();

        if (!$category) {
            return null;
        }

        $categoryId = $category->getId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('foreignKey', $categoryId));
        $criteria->addFilter(new EqualsFilter('isCanonical', true));

        $seoUrl = $this->seoUrlRepository->search($criteria, $context)->first();

        if (!$seoUrl) {
            return null;
        }

        return '/' . $seoUrl->getSeoPathInfo();
    }
}
