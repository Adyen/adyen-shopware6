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

        return new JsonResponse($this->expressCheckoutService->getExpressCheckoutConfigOnProductPage(
            $productId,
            $quantity,
            $salesChannelContext
        ));
    }
}