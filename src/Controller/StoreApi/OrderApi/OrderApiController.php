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
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;

/**
 * Class OrderApiController
 * @package Adyen\Shopware\Controller\StoreApi\OrderApi
 * @Route(defaults={"_routeScope"={"store-api"}})
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
     * @var CartService
     */
    private $cartService;

    /**
     * StoreApiController constructor.
     *
     * @param PaymentMethodsBalanceService $paymentMethodsBalanceService
     * @param OrdersService $ordersService
     * @param OrdersCancelService $ordersCancelService
     * @param PaymentStateDataService $paymentStateDataService
     * @param LoggerInterface $logger
     * @param CartService $cartService
     */
    public function __construct(
        PaymentMethodsBalanceService $paymentMethodsBalanceService,
        OrdersService $ordersService,
        OrdersCancelService $ordersCancelService,
        PaymentStateDataService $paymentStateDataService,
        LoggerInterface $logger,
        CartService $cartService,
    ) {
        $this->paymentMethodsBalanceService = $paymentMethodsBalanceService;
        $this->ordersService = $ordersService;
        $this->ordersCancelService = $ordersCancelService;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->logger = $logger;
        $this->cartService = $cartService;
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
        $paymentMethod = json_decode($request->request->get('paymentMethod', ''), true);
        $amount = json_decode($request->request->get('amount', ''), true);
        return new JsonResponse(
            $this->paymentMethodsBalanceService->getPaymentMethodsBalance($context, $paymentMethod, $amount)
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

    /**
     * @Route(
     *     "/store-api/adyen/giftcard",
     *     name="store-api.action.adyen.giftcard",
     *     methods={"POST"}
     * )
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     * @throws \Adyen\AdyenException
     */
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
                'amount' => (int)$request->request->get('amount'),
                'paymentMethodId' => $request->request->get('paymentMethodId'),
                'balance' => (int)$request->request->get('balance'),
            ]
        );

        return new JsonResponse(['token' => $context->getToken()]);
    }

    /**
     * @Route(
     *     "/store-api/adyen/giftcard/remove",
     *     name="store-api.action.adyen.giftcard.remove",
     *     methods={"POST"}
     * )
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteGiftCardStateData(SalesChannelContext $context, Request $request): JsonResponse
    {
        $stateDateId = $request->request->get('stateDataId');
        $this->paymentStateDataService->deletePaymentStateDataFromId($stateDateId);

        return new JsonResponse(['token' => $context->getToken()]);
    }

    /**
     * @Route(
     *     "/store-api/adyen/giftcard",
     *     name="store-api.action.adyen.giftcard.fetch",
     *     methods={"POST"}
     * )
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     * @throws \Adyen\AdyenException
     */
    public function fetchRedeemedGiftcards(SalesChannelContext $context): JsonResponse
    {
        $fetchedRedeemedGiftcards = $this->paymentStateDataService->fetchRedeemedGiftCardsFromContextToken($context->getToken());

        $remainingOrderAmount = $this->cartService->getCart($context->getToken(), $context)->getPrice()->getTotalPrice();
        $totalDiscount = $this->getGiftcardTotalDiscount($fetchedRedeemedGiftcards, $context);

        $responseArray = [
            'giftcards' => $this->filterGiftcardStateData($fetchedRedeemedGiftcards, $context),
            'remainingAmount' => $remainingOrderAmount - $totalDiscount,
            'totalDiscount' => $totalDiscount
        ];


        return new JsonResponse(['redeemedGiftcards' => $responseArray]);
    }

    private function getGiftcardTotalDiscount($fetchedRedeemedGiftcards, $salesChannelContext)
    {
        $totalGiftcardBalance = 0;
        //$remainingAmountInMinorUnits = $currency->sanitize($this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext)->getPrice()->getTotalPrice(), $currency);
        $remainingAmountInMinorUnits = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext)->getPrice()->getTotalPrice();

        foreach ($fetchedRedeemedGiftcards->getElements() as $fetchedRedeemedGiftcard) {
            $stateData = json_decode($fetchedRedeemedGiftcard->getStateData(), true);
            if (isset($stateData['paymentMethod']['type']) ||
                isset($stateData['paymentMethod']['brand']) ||
                $stateData['paymentMethod']['type'] === 'giftcard') {
                $totalGiftcardBalance += $stateData['giftcard']['value'];
            }
        }

        if ($totalGiftcardBalance > 0) {
            return min($totalGiftcardBalance, $remainingAmountInMinorUnits);
        } else {
            return 0;
        }
    }

    private function filterGiftcardStateData($fetchedRedeemedGiftcards, $salesChannelContext): array
    {
        $responseArray = array();
        $remainingOrderAmount = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext)->getPrice()->getTotalPrice();

        foreach ($fetchedRedeemedGiftcards->getElements() as $fetchedRedeemedGiftcard) {
            $stateData = json_decode($fetchedRedeemedGiftcard->getStateData(), true);
            if (!isset($stateData['paymentMethod']['type']) ||
                !isset($stateData['paymentMethod']['brand']) ||
                $stateData['paymentMethod']['type'] !== 'giftcard') {
                unset($fetchedRedeemedGiftcards);
                continue;
            }
            $deductedAmount = min($remainingOrderAmount, $stateData['giftcard']['value']);

            $responseArray[] = [
                'stateDataId' => $fetchedRedeemedGiftcard->getId(),
                'brand' => $stateData['paymentMethod']['brand'],
                'title' => $stateData['giftcard']['title'],
                'balance' => $stateData['giftcard']['value'],
                'deductedAmount' => $deductedAmount
            ];

            $remainingOrderAmount -= $deductedAmount;
        }
        return $responseArray;
    }
}
