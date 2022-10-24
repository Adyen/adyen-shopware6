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

namespace Adyen\Shopware\Controller\StoreApi\OrderApi;

use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Service\PaymentMethodsBalanceService;
use Adyen\Shopware\Service\OrdersService;
use Adyen\Shopware\Service\OrdersCancelService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

/**
 * Class OrderApiController
 * @package Adyen\Shopware\Controller\StoreApi\Donate
 * @RouteScope(scopes={"store-api"})
 */
class OrderApiController
{
    /**
     * @var PaymentMethodsBalanceService
     */
    private $paymentMethodsBalanceService;
    /**
     * @var OrdersService
     */
    private $ordersService;
    /**
     * @var OrdersService
     */
    private $ordersCancelService;
    /**
     * @var PaymentStateDataService
     */
    private $paymentStateDataService;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * StoreApiController constructor.
     *
     * @param PaymentMethodsBalanceService $paymentMethodsBalanceService
     * @param OrdersService $ordersService
     * @param OrdersCancelService $ordersCancelService
     * @param PaymentStateDataService $paymentStateDataService
     * @param LoggerInterface $logger
     */
    public function __construct(
        PaymentMethodsBalanceService $paymentMethodsBalanceService,
        OrdersService $ordersService,
        OrdersCancelService $ordersCancelService,
        PaymentStateDataService $paymentStateDataService,
        LoggerInterface $logger
    ) {
        $this->paymentMethodsBalanceService = $paymentMethodsBalanceService;
        $this->ordersService = $ordersService;
        $this->ordersCancelService = $ordersCancelService;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->logger = $logger;
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-methods/balance",
     *     name="store-api.action.adyen.payment-methods.balance",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    public function getPaymentMethodsBalance(SalesChannelContext $context, Request $request): JsonResponse
    {
        $paymentMethodData = $request->request->get('paymentMethod');

        return new JsonResponse(
            $this->paymentMethodsBalanceService->getPaymentMethodsBalance($context, (array) $paymentMethodData)
        );
    }

    /**
     * @Route(
     *     "/store-api/adyen/orders",
     *     name="store-api.action.adyen.orders",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function createOrder(SalesChannelContext $context, Request $request): JsonResponse
    {
        $uuid = Uuid::randomHex();
        $orderAmount = $request->request->get('orderAmount');
        $currency = $request->request->get('currency');

        return new JsonResponse($this->ordersService->createOrder($context, $uuid, $orderAmount, $currency));
    }

    /**
     * @Route(
     *     "/store-api/adyen/orders/cancel",
     *     name="store-api.action.adyen.orders.cancel",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function cancelOrder(SalesChannelContext $context, Request $request): JsonResponse
    {
        $orderData = $request->request->get('orderData');
        $pspReference = $request->request->get('pspReference');

        // todo , also delete giftcard state data from context if exists

        return new JsonResponse($this->ordersCancelService->cancelOrder($context, $orderData, $pspReference));
    }

    /**
     * @Route(
     *     "/store-api/adyen/giftcard",
     *     name="store-api.action.adyen.giftcard",
     *     methods={"POST", "GET"}
     * )
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    public function giftcardStateData(SalesChannelContext $context, Request $request): JsonResponse
    {
        if ('POST' === $request->getMethod()) {
            // store giftcard state data for context
            $stateData = $request->request->get('giftcardStateData');
            if ('giftcard' !== $stateData['paymentMethod']['type']) {
                throw new ValidationException('Only giftcard state data is allowed to be stored.');
            }
            $this->paymentStateDataService->insertPaymentStateData(
                $context->getToken(),
                json_encode($stateData),
                $request->server->get('HTTP_ORIGIN')
            );

            return new JsonResponse([]);
        } else {
            // fetch giftcard data

            return new JsonResponse([]);
        }
    }
}
