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

use Adyen\Shopware\Service\PaymentMethodsBalanceService;
use Adyen\Shopware\Service\OrdersService;
use Adyen\Shopware\Service\OrdersCancelService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
     * StoreApiController constructor.
     *
     * @param PaymentMethodsBalanceService $paymentMethodsBalanceService
     * @param OrdersService $orderService
     * @param OrdersCancelService $orderCancelService
     */
    public function __construct(
        PaymentMethodsBalanceService $paymentMethodsBalanceService,
        OrdersService $ordersService,
        OrdersCancelService $ordersCancelService
    ) {
        $this->paymentMethodsBalanceService = $paymentMethodsBalanceService;
        $this->ordersService = $ordersService;
        $this->ordersCancelService = $ordersCancelService;
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
        $number = $request->request->get('number');
        $type = $request->request->get('type');
        $cvc = $request->request->get('cvc');

        return new JsonResponse(
            $this->paymentMethodsBalanceService->getPaymentMethodsBalance($context, $type, $number, $cvc)
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

        return new JsonResponse($this->ordersCancelService->cancelOrder($context, $orderData, $pspReference));
    }
}
