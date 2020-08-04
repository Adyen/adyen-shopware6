<?php
declare(strict_types=1);
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

use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentStatusService;
use Symfony\Component\HttpFoundation\Request;
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
     * @var CheckoutStateDataValidator
     */
    private $checkoutStateDataValidator;

    /**
     * @var PaymentStatusService
     */
    private $paymentStatusService;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

    /**
     * SalesChannelApiController constructor.
     *
     * @param OriginKeyService $originKeyService
     * @param PaymentMethodsService $paymentMethodsService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentDetailsService $paymentDetailsService
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStatusService $paymentStatusService
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentResponseService $paymentResponseService
     */
    public function __construct(
        OriginKeyService $originKeyService,
        PaymentMethodsService $paymentMethodsService,
        SalesChannelRepository $salesChannelRepository,
        PaymentDetailsService $paymentDetailsService,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStatusService $paymentStatusService,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentResponseService $paymentResponseService
    ) {
        $this->originKeyService = $originKeyService;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStatusService = $paymentStatusService;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentResponseService = $paymentResponseService;
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
        return new JsonResponse(
            [
                $this->originKeyService
                    ->getOriginKeyForOrigin($this->salesChannelRepository->getSalesChannelUrl($context))
                    ->getOriginKey()
            ]
        );
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
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function postPaymentDetails(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse {

        $orderId = $request->request->get('orderId');
        if (empty($orderId)) {
            // TODO error handling
        }

        // Get state data object if sent
        $stateData = $request->request->get('stateData');

        // Validate stateData object
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
        }

        if (empty($stateData['details'])) {
            // handle error
        }

        $details = $stateData['details'];

        $result = $this->paymentDetailsService->doPaymentDetails(
            $details,
            $this->paymentResponseService
                ->getWithOrderId($orderId, $context->getToken())
                ->getOrderNumber(),
            $context
        );

        return new JsonResponse($this->paymentResponseHandler->handleAdyenApis($result));
    }

    /**
     * @RouteScope(scopes={"sales-channel-api"})
     * @Route(
     *     "/sales-channel-api/v1/adyen/payment-status",
     *     name="sales-channel-api.action.adyen.payment-status",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentStatus(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (empty($request->get('orderId'))) {
            return new JsonResponse('Order ID not provided');
        }

        return new JsonResponse(
            $this->paymentStatusService->getPaymentStatusWithOrderId(
                $request->get('orderId'),
                $context
            )
        );
    }
}
