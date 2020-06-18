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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use RuntimeException;

class LocaleService
{

    /**
     * @var EntityRepositoryInterface $localeRepository
     */
    private $localeRepository;

    /**
     * @var EntityRepositoryInterface $languageRepository
     */
    private $languageRepository;

    public function __construct(
        EntityRepositoryInterface $localeRepository,
        EntityRepositoryInterface $languageRepository
    ) {
        $this->localeRepository = $localeRepository;
        $this->languageRepository = $languageRepository;
    }

    public function getLocaleFromLanguageId(string $languageId): LocaleEntity
    {
        $languageCriteria = new Criteria([$languageId]);

        /** @var null|LanguageEntity $language */
        $language = $this->languageRepository->search($languageCriteria, Context::createDefaultContext())->first();

        $localeCriteria = new Criteria([$language->getLocaleId()]);

        /** @var null|LocaleEntity $locale */
        $locale = $this->localeRepository->search($localeCriteria, Context::createDefaultContext())->first();

        if (null === $locale) {
            throw new RuntimeException('missing locale entity');
        }

        return $locale;
    }

}
