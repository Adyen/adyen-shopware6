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

use Adyen\AdyenException;
use Adyen\Shopware\Exception\ResolveCountryException;
use Adyen\Shopware\Exception\ResolveShippingMethodException;
use Adyen\Shopware\Service\ExpressCheckoutService;
use Exception;
use JsonException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class ExpressCheckoutController
 *
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
    public function __construct(ExpressCheckoutService $expressCheckoutService)
    {
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
     *
     * @return JsonResponse
     */
    public function getExpressCheckoutConfig(
        Request $request,
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

            if (array_key_exists('error', $config)) {
                if ($config['error'] === 'ResolveCountryException') {
                    throw new  ResolveCountryException($config['message']);
                }

                if ($config['error'] === 'ResolveShippingMethodException') {
                    throw new  ResolveShippingMethodException($config['message']);
                }
            }

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
            ], 400);
        }
    }

    /**
     * Creates a cart with the provided product and calculates it with the resolved shipping location and method.
     *
     * @param RequestDataBag $data
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     *
     * @return array The cart, shipping methods, selected shipping method, and payment methods.
     * @throws Exception
     */
    public function createCart(
        RequestDataBag $data,
        SalesChannelContext $salesChannelContext
    ): array {
        $productId = $data->get('productId');
        $quantity = (int)$data->get('quantity');
        $formattedHandlerIdentifier = $data->get('formattedHandlerIdentifier') ?? '';
        $newAddress = $data->get('newAddress')->all();
        $newShipping = $data->get('newShippingMethod')->all();
        $guestEmail = $data->get('email');

        $customer = $salesChannelContext->getCustomer();
        $makeNewCustomer = $customer === null;
        $createNewAddress = $customer && $customer->getGuest();

        return $this->expressCheckoutService
            ->createCart(
                $productId,
                $quantity,
                $salesChannelContext,
                $newAddress,
                $newShipping,
                $formattedHandlerIdentifier,
                $guestEmail,
                $makeNewCustomer,
                $createNewAddress
            );
    }

    /**
     * @param RequestDataBag $data
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     *
     * @throws ResolveCountryException
     * @throws ResolveShippingMethodException
     * @throws Exception
     */
    public function createCartForPayPalExpressCheckout(
        RequestDataBag $data,
        SalesChannelContext $salesChannelContext
    ): array {
        $customer = $salesChannelContext->getCustomer();

        if ($customer && !$customer->getGuest()) {
            return $this->createCart($data, $salesChannelContext);
        }

        $productId = $data->get('productId');
        $quantity = (int)$data->get('quantity');
        $formattedHandlerIdentifier = $data->get('formattedHandlerIdentifier') ?? '';

        return $this->expressCheckoutService->createCartForPayPalGuestExpressCheckout(
            $productId,
            $quantity,
            $salesChannelContext,
            $formattedHandlerIdentifier
        );
    }

    /**
     * Updates the SalesChannelContext for guest customer.
     *
     * @param string $customerId The ID of the customer whose context should be updated.
     * @param SalesChannelContext $salesChannelContext The existing sales channel context to be updated.
     *
     * @return SalesChannelContext The updated SalesChannelContext with the customer's details.
     * @throws \Exception If the customer cannot be found.
     *
     */
    public function changeContext(string $customerId, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        return $this->expressCheckoutService->changeContext($customerId, $salesChannelContext);
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @param string $cartToken
     *
     * @return JsonResponse
     *
     * @throws JsonException
     */
    public function updatePayPalOrder(
        Request $request,
        SalesChannelContext $salesChannelContext,
        string $cartToken
    ): JsonResponse {
        $newAddress = $request->request->all()['newAddress'] ?? null;
        $newShipping = $request->request->all()['newShippingMethod'] ?? null;

        if ($newAddress === null) {
            $newAddress = [];
        }

        if ($newShipping === null) {
            $newShipping = [];
        }

        $paymentData = $request->request->get('currentPaymentData');
        $pspReference = $request->request->get('pspReference');

        try {
            $paypalUpdateOrderResponse = $this->expressCheckoutService->paypalUpdateOrder(
                $cartToken,
                [
                    'paymentData' => $paymentData,
                    'pspReference' => $pspReference,
                ],
                $salesChannelContext,
                $newAddress,
                $newShipping
            );

            return new JsonResponse($paypalUpdateOrderResponse->toArray());
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
        } catch (AdyenException $e) {
            return new JsonResponse([
                'error' => [
                    'reason' => 'OTHER_ERROR',
                    'message' => $e->getMessage(),
                ]
            ], 400);
        }
    }
}
