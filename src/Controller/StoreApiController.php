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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Controller;

use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentStatusService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Store\Api\AbstractStoreController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class StoreApiController extends AbstractStoreController
{
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
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;
    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * StoreApiController constructor.
     *
     * @param PaymentMethodsService $paymentMethodsService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentDetailsService $paymentDetailsService
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStatusService $paymentStatusService
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentResponseService $paymentResponseService
     * @param LoggerInterface $logger
     */
    public function __construct(
        PaymentMethodsService $paymentMethodsService,
        SalesChannelRepository $salesChannelRepository,
        PaymentDetailsService $paymentDetailsService,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStatusService $paymentStatusService,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentResponseService $paymentResponseService,
        LoggerInterface $logger
    ) {
        $this->paymentMethodsService = $paymentMethodsService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStatusService = $paymentStatusService;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
    }

    /**
     * @RouteScope(scopes={"store-api"})
     * @Route(
     *     "/store-api/v{version}/adyen/payment-methods",
     *     name="store-api.action.adyen.payment-methods",
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
     * @RouteScope(scopes={"store-api"})
     * @Route(
     *     "/store-api/v{version}/adyen/payment-details",
     *     name="store-api.action.adyen.payment-details",
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
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId);
        if (!$paymentResponse) {
            $this->logger->error('Could not find a transaction', ['orderId' => $orderId]);
            return new JsonResponse([], 404);
        }

        // Get state data object if sent
        $stateData = $request->request->get('stateData');

        // Validate stateData object
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
        }

        if (empty($stateData['details'])) {
            $this->logger->error(
                'Details missing in $stateData',
                ['stateData' => $stateData]
            );
            return new JsonResponse([], 400);
        }

        $details = $stateData['details'];

        try {
            $result = $this->paymentDetailsService->doPaymentDetails(
                $details,
                $paymentResponse->getOrderTransaction()
            );
        } catch (PaymentFailedException $exception) {
            $this->logger->error(
                'Error occurred finalizing payment',
                ['orderId' => $orderId, 'paymentDetails' => $details]
            );
            return new JsonResponse([], 500);
        }

        return new JsonResponse($this->paymentResponseHandler->handleAdyenApis($result));
    }

    /**
     * @RouteScope(scopes={"store-api"})
     * @Route(
     *     "/store-api/v{version}/adyen/payment-status",
     *     name="store-api.action.adyen.payment-status",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentStatus(Request $request, SalesChannelContext $context): JsonResponse
    {
        $orderId = $request->get('orderId');
        if (empty($orderId)) {
            return new JsonResponse('Order ID not provided');
        }

        try {
            return new JsonResponse(
                $this->paymentStatusService->getWithOrderId($orderId)
            );
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            return new JsonResponse(["isFinal" => true]);
        }
    }
}
