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

use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Adyen\Shopware\Service\OriginKeyService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;

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
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var PaymentDetailsService
     */
    private $paymentDetailsService;

    /**
     * SalesChannelApiController constructor.
     *
     * @param OriginKeyService $originKeyService
     * @param PaymentMethodsService $paymentMethodsService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentDetailsService $paymentDetailsService
     */
    public function __construct(
        OriginKeyService $originKeyService,
        PaymentMethodsService $paymentMethodsService,
        SalesChannelRepository $salesChannelRepository,
        PaymentDetailsService $paymentDetailsService
    ) {
        $this->originKeyService = $originKeyService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentDetailsService = $paymentDetailsService;
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
                ->getOriginKeyForOrigin($this->salesChannelRepository->getSalesChannelUrl($context))
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
        return new JsonResponse($this->paymentMethodsService->getPaymentMethods($context));
    }

    /**
     * @RouteScope(scopes={"sales-channel-api"})
     * @Route(
     *     "/sales-channel-api/v1/adyen/payment-details",
     *     name="sales-channel-api.action.adyen.payment-details",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function postPaymentDetails(SalesChannelContext $context): JsonResponse
    {
        return new JsonResponse($this->paymentDetailsService->doPaymentDetails($context));
    }
}
