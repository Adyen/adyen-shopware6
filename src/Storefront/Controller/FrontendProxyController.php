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

namespace Adyen\Shopware\Storefront\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class FrontendProxyController extends StorefrontController
{

    private CartOrderRoute $cartOrderRoute;
    private CartService $cartService;
    private HandlePaymentMethodRoute $handlePaymentMethodRoute;

    public function __construct(
        AbstractCartOrderRoute $cartOrderRoute,
        AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute,
        CartService $cartService
    )
    {
        $this->cartOrderRoute = $cartOrderRoute;
        $this->cartService = $cartService;
        $this->handlePaymentMethodRoute = $handlePaymentMethodRoute;
    }

    /**
     * @Route(
     *     "/adyen/proxy-checkout-order",
     *     name="payment.adyen.proxy-checkout-order",
     *     defaults={"XmlHttpRequest"=true, "csrf_protected": false},
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $salesChannelContext
     * @param RequestDataBag $data
     * @return JsonResponse
     */
    public function checkoutOrder(SalesChannelContext $salesChannelContext, RequestDataBag $data): JsonResponse
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        return new JsonResponse($this->cartOrderRoute->order($cart, $salesChannelContext, $data)->getOrder());
    }

    /**
     * @Route(
     *     "/adyen/proxy-handle-payment",
     *     name="payment.adyen.proxy-handle-payment",
     *     defaults={"XmlHttpRequest"=true, "csrf_protected": false},
     *     methods={"POST"}
     * )
     */
    public function handlePayment(Request $request, SalesChannelContext $salesChannelContext)
    {
        $routeResponse = $this->handlePaymentMethodRoute->load($request, $salesChannelContext);

        return new JsonResponse($routeResponse->getObject());
    }
}
