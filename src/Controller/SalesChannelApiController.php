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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Adyen\Shopware\Service\OriginKeyService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;


class SalesChannelApiController extends AbstractController
{

    /**
     * @var OriginKeyService
     */
    private $originKeyService;

    /**
     * @var EntityRepositoryInterface
     */
    private $domainRepository;

    public function __construct(
        OriginKeyService $originKeyService,
        EntityRepositoryInterface $domainRepository
    ) {
        $this->originKeyService = $originKeyService;
        $this->domainRepository = $domainRepository;
    }

    /**
     * @RouteScope(scopes={"sales-channel-api"})
     * @Route("/sales-channel-api/v1/adyen/origin-key", name="sales-channel-api.action.adyen.origin-key", methods={"GET"})
     */
    public function originKey(SalesChannelContext $context): JsonResponse
    {

        return new JsonResponse([
            $this->originKeyService
                ->getOriginKeyForOrigin($this->getSalesChannelUrl($context))
                ->getOriginKey()
        ]);
    }

    /**
     * @param SalesChannelContext $context
     * @return string
     */
    private function getSalesChannelUrl(SalesChannelContext $context): string
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
}
