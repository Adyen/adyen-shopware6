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

use Adyen\Shopware\Exception\ResolveCountryException;
use Adyen\Shopware\Exception\ResolveShippingMethodException;
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
        $formattedHandlerIdentifier = $request->request->get('formattedHandlerIdentifier') ?? '';
        $newAddress = $request->request->all()['newAddress'] ?? null;
        $newShipping = $request->request->all()['newShippingMethod'] ?? null;

        if ($newAddress === null) {
            $newAddress = [];
        }

        if ($newShipping === null) {
            $newShipping = [];
        }

        try {
            $config = $this->expressCheckoutService->getExpressCheckoutConfig(
                $productId,
                $quantity,
                $salesChannelContext,
                $newAddress,
                $newShipping,
                $formattedHandlerIdentifier
            );

            return new JsonResponse($config);
        } catch (ResolveCountryException $e) {
            return new JsonResponse([
                'error' => [
                    'reason' => 'SHIPPING_ADDRESS_INVALID',
                    'message' => $e->getMessage(),
                    'intent' => 'SHIPPING_ADDRESS',
                ]
            ], 400);
        } catch (ResolveShippingMethodException $e) {
            return new JsonResponse([
                'error' => [
                    'reason' => 'SHIPPING_OPTION_INVALID',
                    'message' => $e->getMessage(),
                    'intent' => 'SHIPPING_OPTION',
                ]
            ], 400);
        } catch (\Exception $e) {
            // Fallback for unexpected errors
            return new JsonResponse([
                'error' => [
                    'reason' => 'OTHER_ERROR',
                    'message' => $e->getMessage(),
                ]
            ], 500);
        }
    }
}
