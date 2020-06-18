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

use Adyen\Shopware\Service\PaymentMethodsService;
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
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * @var EntityRepositoryInterface
     */
    private $entityRepository;

    public function __construct(
        OriginKeyService $originKeyService,
        PaymentMethodsService $paymentMethodsService,
        EntityRepositoryInterface $entityRepository
    ) {
        $this->originKeyService = $originKeyService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->entityRepository = $entityRepository;
    }

    /**
     * @RouteScope(scopes={"sales-channel-api"})
     * @Route(
     *     "/sales-channel-api/v1/adyen/origin-key",
     *     name="sales-channel-api.action.adyen.origin-key",
     *     methods={"GET"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
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
     * @RouteScope(scopes={"sales-channel-api"})
     * @Route(
     *     "/sales-channel-api/v1/adyen/payment-methods",
     *     name="sales-channel-api.action.adyen.payment-methods",
     *     methods={"GET"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentMethods(SalesChannelContext $context): JsonResponse
    {

        /** @var EntityRepositoryInterface $orderRepository */
        $orderRepository = $this->container->get('order.repository');

        /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection $entities */
        $entities = $orderRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('orderNumber', 10000)),
            \Shopware\Core\Framework\Context::createDefaultContext()
        );

        /** @var \Shopware\Core\Framework\DataAbstractionLayer\Entity $order */
        $order = $entities->first();

        return new JsonResponse($this->paymentMethodsService->getPaymentMethods($order));
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

        $domainEntity = $this->entityRepository
            ->search($criteria, $context->getContext())
            ->first();

        if (!$domainEntity) {
            throw new SalesChannelDomainNotFoundException($context->getSalesChannel());
        }

        $url = $domainEntity->getUrl();

        return $url;
    }
}
