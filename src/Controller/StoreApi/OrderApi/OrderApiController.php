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

use Adyen\AdyenException;
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Service\PaymentMethodsBalanceService;
use Adyen\Shopware\Service\OrdersService;
use Adyen\Shopware\Service\OrdersCancelService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class OrderApiController
{
    /**
     * @var PaymentMethodsBalanceService
     */
    private PaymentMethodsBalanceService $paymentMethodsBalanceService;

    /**
     * @var OrdersService
     */
    private OrdersService $ordersService;

    /**
     * @var OrdersService|OrdersCancelService
     */
    private OrdersCancelService|OrdersService $ordersCancelService;

    /**
     * @var PaymentStateDataService
     */
    private PaymentStateDataService $paymentStateDataService;

    /**
     * StoreApiController constructor.
     *
     * @param PaymentMethodsBalanceService $paymentMethodsBalanceService
     * @param OrdersService $ordersService
     * @param OrdersCancelService $ordersCancelService
     * @param PaymentStateDataService $paymentStateDataService
     */
    public function __construct(
        PaymentMethodsBalanceService $paymentMethodsBalanceService,
        OrdersService $ordersService,
        OrdersCancelService $ordersCancelService,
        PaymentStateDataService $paymentStateDataService
    ) {
        $this->paymentMethodsBalanceService = $paymentMethodsBalanceService;
        $this->ordersService = $ordersService;
        $this->ordersCancelService = $ordersCancelService;
        $this->paymentStateDataService = $paymentStateDataService;
    }

    /**
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/store-api/adyen/payment-methods/balance',
        name: 'store-api.action.adyen.payment-methods.balance',
        methods: ['POST']
    )]
    public function getPaymentMethodsBalance(SalesChannelContext $context, Request $request): JsonResponse
    {
        $paymentMethod = json_decode($request->request->get('paymentMethod', ''), true);
        $amount = json_decode($request->request->get('amount', ''), true);
        return new JsonResponse(
            $this->paymentMethodsBalanceService->getPaymentMethodsBalance($context, $paymentMethod, $amount)
        );
    }

    /**
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/store-api/adyen/orders', name: 'store-api.action.adyen.orders', methods: ['POST'])]
    public function createOrder(SalesChannelContext $context, Request $request): JsonResponse
    {
        $uuid = Uuid::randomHex();
        $orderAmount = $request->request->get('orderAmount');
        $currency = $request->request->get('currency');

        return new JsonResponse($this->ordersService->createOrder($context, $uuid, $orderAmount, $currency));
    }

    /**
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/store-api/adyen/orders/cancel', name: 'store-api.action.adyen.orders.cancel', methods: ['POST'])]
    public function cancelOrder(SalesChannelContext $context, Request $request): JsonResponse
    {
        $orderData = $request->request->get('orderData');
        $pspReference = $request->request->get('pspReference');

        return new JsonResponse($this->ordersCancelService->cancelOrder($context, $orderData, $pspReference));
    }

    /**
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     * @throws AdyenException
     */
    #[Route('/store-api/adyen/giftcard', name: 'store-api.action.adyen.giftcard', methods: ['POST'])]
    public function giftcardStateData(SalesChannelContext $context, Request $request): JsonResponse
    {
        // store giftcard state data for context
        $stateData = json_decode($request->request->get('stateData', ''), true);
        if ('giftcard' !== $stateData['paymentMethod']['type']) {
            throw new ValidationException('Only giftcard state data is allowed to be stored.');
        }
        $this->paymentStateDataService->insertPaymentStateData(
            $context->getToken(),
            json_encode($stateData),
            [
                'amount' => (int) $request->request->get('amount'),
                'paymentMethodId' => $request->request->get('paymentMethodId'),
                'balance' => (int) $request->request->get('balance'),
            ]
        );

        return new JsonResponse(['paymentMethodId' => $request->request->get('paymentMethodId')]);
    }

    /**
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    #[Route('/store-api/adyen/giftcard/remove', name: 'store-api.action.adyen.giftcard.remove', methods: ['POST'])]
    public function deleteGiftCardStateData(SalesChannelContext $context): JsonResponse
    {
        $this->paymentStateDataService->deletePaymentStateDataFromContextToken($context->getToken());

        return new JsonResponse(['token' => $context->getToken()]);
    }
}
