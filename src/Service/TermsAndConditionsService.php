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

use Adyen\Shopware\Service\Repository\TermsAndConditionsRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class TermsAndConditionsService
{
    /**
     * @var TermsAndConditionsRepository
     */
    private TermsAndConditionsRepository $termsAndConditionsRepository;

    public function __construct(TermsAndConditionsRepository $termsAndConditionsRepository)
    {
        $this->termsAndConditionsRepository = $termsAndConditionsRepository;
    }

    /**
     * Retrieves the relative path to the Terms and Conditions page from the database.
     *
     * @param string|null $tosPageId The CMS page ID containing the Terms and Conditions.
     * @param SalesChannelContext $context The sales channel context.
     * @return string|null The relative SEO URL or null if not found.
     */
    public function getShopTermsAndConditionsPath(?string $tosPageId, SalesChannelContext $context): ?string
    {
        return $this->termsAndConditionsRepository->getTermsAndConditionsPath($tosPageId, $context->getContext());
    }
}
