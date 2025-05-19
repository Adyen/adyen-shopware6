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

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Service\Repository\TermsAndConditionsRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class TermsAndConditionsService
{
    /**
     * @var TermsAndConditionsRepository
     */
    private TermsAndConditionsRepository $termsAndConditionsRepository;

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var SalesChannelRepository
     */
    private SalesChannelRepository $salesChannelRepository;

    /**
     * @param TermsAndConditionsRepository $termsAndConditionsRepository
     * @param ConfigurationService $configurationService
     * @param SalesChannelRepository $salesChannelRepository
     */
    public function __construct(
        TermsAndConditionsRepository $termsAndConditionsRepository,
        ConfigurationService $configurationService,
        SalesChannelRepository $salesChannelRepository
    ) {
        $this->termsAndConditionsRepository = $termsAndConditionsRepository;
        $this->configurationService = $configurationService;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    /**
     * Retrieves the Terms and Conditions URL
     *
     * @param SalesChannelContext $context The sales channel context.
     * @return string|null The Terms and Conditions URL
     */
    public function getTermsAndConditionsUrl(SalesChannelContext $salesChannelContext): ?string
    {
        $termsAndConditionsUrl = $this->configurationService->getAdyenGivingTermsAndConditionsUrl(
            $salesChannelContext->getSalesChannel()->getId()
        );

        if (empty($termsAndConditionsUrl)) {
            $tosPageId = $this->configurationService->getTosPageId(
                $salesChannelContext->getSalesChannel()->getId()
            );

            $termsAndConditionsPath = $this->getShopTermsAndConditionsPath(
                $tosPageId,
                $salesChannelContext
            );

            if (!empty($termsAndConditionsPath)) {
                $baseUrl = $this->salesChannelRepository->getCurrentDomainUrl($salesChannelContext);
                $termsAndConditionsUrl = $baseUrl . $termsAndConditionsPath;
            }
        }

        return $termsAndConditionsUrl;
    }

    /**
     * Retrieves the relative path to the Terms and Conditions page from the database.
     *
     * @param string|null $tosPageId The CMS page ID containing the Terms and Conditions.
     * @param SalesChannelContext $context The sales channel context.
     * @return string|null The relative SEO URL or null if not found.
     */
    private function getShopTermsAndConditionsPath(
        ?string $tosPageId,
        SalesChannelContext $salesChannelContext
    ): ?string {
        return $this->termsAndConditionsRepository->getTermsAndConditionsPath(
            $tosPageId,
            $salesChannelContext->getContext()
        );
    }
}
