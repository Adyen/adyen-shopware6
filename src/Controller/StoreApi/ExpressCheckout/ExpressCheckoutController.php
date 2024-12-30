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

namespace Adyen\Shopware\Controller\StoreApi\ExpressCheckout;

use Adyen\Shopware\Service\ExpressCheckoutService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class ExpressCheckoutController
 * @package Adyen\Shopware\Controller\StoreApi\ExpressCheckout
 * @Route(defaults={"_routeScope"={"store-api"}})
 */
class ExpressCheckoutController
{
    /**
     * @var ExpressCheckoutService
     */
    private ExpressCheckoutService $expressCheckoutService;

    /**
     * StoreApiController constructor.
     *
     * @param ExpressCheckoutService $expressCheckoutService
     */
    public function __construct(
        ExpressCheckoutService $expressCheckoutService
    ) {
        $this->expressCheckoutService = $expressCheckoutService;
    }

    /**
     * @Route(
     *     "/store-api/adyen/express-checkout-config",
     *     name="store-api.action.adyen.express-checkout-config",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     */
    public function getExpressCheckoutConfig(
        Request             $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        $productId = $request->request->get('productId');
        $quantity = (int)$request->request->get('quantity');
        $newAddress = $request->request->get('newAddress');
        $newShipping = $request->request->get('newShippingMethod');

        if ($newAddress === null) {
            $newAddress = [];
        }

        if ($newShipping === null) {
            $newShipping = [];
        }

        return new JsonResponse($this->expressCheckoutService->getExpressCheckoutConfigOnProductPage(
            $productId,
            $quantity,
            $salesChannelContext,
            $newAddress,
            $newShipping
        ));
    }

    /**
     * Creates a cart with the provided product and calculates it with the resolved shipping location and method.
     *
     * @param string $productId The ID of the product.
     * @param int $quantity The quantity of the product.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @param array $newAddress Optional new address details.
     * @param array $newShipping Optional new shipping method details.
     * @return array The cart, shipping methods, selected shipping method, and payment methods.
     * @throws \Exception
     */
    public function createCart(
        string $productId,
        int $quantity,
        SalesChannelContext $salesChannelContext,
        array $newAddress = [],
        array $newShipping = []
    ): array {
        return $this->expressCheckoutService
            ->createCart($productId, $quantity, $salesChannelContext, $newAddress, $newShipping);
    }
}
