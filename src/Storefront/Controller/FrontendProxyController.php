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

use Adyen\Shopware\Controller\StoreApi\Donate\DonateController;
use Adyen\Shopware\Controller\StoreApi\ExpressCheckout\ExpressCheckoutController;
use Adyen\Shopware\Controller\StoreApi\OrderApi\OrderApiController;
use Adyen\Shopware\Controller\StoreApi\Payment\PaymentController;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Util\ShopwarePaymentTokenValidator;
use Error;
use Exception;
use Shopware\Core\Checkout\Cart\Exception\InvalidCartException;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Exception\EmptyCartException;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class FrontendProxyController extends StorefrontController
{
    /**
     * @var AbstractCartOrderRoute
     */
    private AbstractCartOrderRoute $cartOrderRoute;

    /**
     * @var CartService
     */
    private CartService $cartService;

    /**
     * @var AbstractContextSwitchRoute
     */
    private AbstractContextSwitchRoute $contextSwitchRoute;

    /**
     * @var AbstractHandlePaymentMethodRoute
     */
    private AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute;

    /**
     * @var RouterInterface
     */
    private RouterInterface $router;

    /**
     * @var PaymentController
     */
    private PaymentController $paymentController;

    /**
     * @var OrderApiController
     */
    private OrderApiController $orderApiController;

    /**
     * @var DonateController
     */
    private DonateController $donateController;

    /**
     * @var ExpressCheckoutController
     */
    private ExpressCheckoutController $expressCheckoutController;

    /**
     * @var ShopwarePaymentTokenValidator
     */
    private ShopwarePaymentTokenValidator $paymentTokenValidator;

    /**
     * @var AdyenPaymentService
     */
    private AdyenPaymentService $adyenPaymentService;

    /**
     * @param AbstractCartOrderRoute $cartOrderRoute
     * @param AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute
     * @param AbstractContextSwitchRoute $contextSwitchRoute
     * @param CartService $cartService
     * @param RouterInterface $router
     * @param PaymentController $paymentController
     * @param OrderApiController $orderApiController
     * @param DonateController $donateController
     * @param ShopwarePaymentTokenValidator $paymentTokenValidator
     * @param AdyenPaymentService $adyenPaymentService
     */
    public function __construct(//NOSONAR
        AbstractCartOrderRoute $cartOrderRoute,//NOSONAR
        AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute,//NOSONAR
        AbstractContextSwitchRoute $contextSwitchRoute,//NOSONAR
        CartService $cartService,//NOSONAR
        RouterInterface $router,//NOSONAR
        PaymentController $paymentController,//NOSONAR
        OrderApiController $orderApiController,//NOSONAR
        DonateController $donateController,//NOSONAR
        ExpressCheckoutController $expressCheckoutController,
        ShopwarePaymentTokenValidator    $paymentTokenValidator,//NOSONAR
        AdyenPaymentService         $adyenPaymentService
    ) {//NOSONAR
        $this->cartOrderRoute = $cartOrderRoute;
        $this->cartService = $cartService;
        $this->handlePaymentMethodRoute = $handlePaymentMethodRoute;
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->router = $router;
        $this->paymentController = $paymentController;
        $this->orderApiController = $orderApiController;
        $this->donateController = $donateController;
        $this->expressCheckoutController = $expressCheckoutController;
        $this->paymentTokenValidator = $paymentTokenValidator;
        $this->adyenPaymentService = $adyenPaymentService;
    }

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
    */
    #[Route(
        '/adyen/proxy-switch-context',
        name: 'payment.adyen.proxy-switch-context',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['PATCH']
    )]
    public function switchContext(RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse
    {
        return $this->contextSwitchRoute->switchContext($data, $context);
    }

    #[Route(
        '/adyen/proxy-checkout-order',
        name: 'payment.adyen.proxy-checkout-order',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function checkoutOrder(RequestDataBag $data, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        try {
            $order = $this->cartOrderRoute->order($cart, $salesChannelContext, $data)->getOrder();

            return new JsonResponse(['id' => $order->getId()]);
        } catch (InvalidCartException|Error|EmptyCartException) {
            $this->addCartErrors(
                $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext)
            );

            return new JsonResponse(
                [
                    'url' => $this->generateUrl(
                        'frontend.checkout.cart.page',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    )
                ],
                400
            );
        }
    }

    #[Route(
        '/adyen/proxy-handle-payment',
        name: 'payment.adyen.proxy-handle-payment',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function handlePayment(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $routeResponse = $this->handlePaymentMethodRoute->load($request, $salesChannelContext);

        return new JsonResponse($routeResponse->getObject());
    }

    #[Route(
        '/adyen/proxy-checkout-order-express-product',
        name: 'payment.adyen.proxy-checkout-order-express-product',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function checkoutOrderExpressProduct(
        RequestDataBag $data,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        try {
            $cartData = $this->expressCheckoutController->createCart(
                $data,
                $salesChannelContext
            );
            $cart = $cartData['cart'];
            $updatedSalesChannelContext = $cartData['updatedSalesChannelContext'];
            $order = $this->cartOrderRoute->order($cart, $updatedSalesChannelContext, $data)->getOrder();

            return new JsonResponse(['id' => $order->getId(), 'customerId' => $cartData['customerId']]);
        } catch (InvalidCartException|EmptyCartException|Error|Exception $exception) {
            $this->addCartErrors(
                $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext)
            );

            return new JsonResponse(
                [
                    'url' => $this->generateUrl(
                        'frontend.checkout.cart.page',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    )
                ],
                400
            );
        }
    }

    #[Route(
        '/adyen/proxy-handle-payment-express-product',
        name: 'payment.adyen.proxy-handle-payment-express-product',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function handlePaymentExpressProduct(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        $customer = $salesChannelContext->getCustomer();

        if ($customer === null || $customer->getGuest()) {
            $customerId = $request->request->get('customerId');
            $salesChannelContext = $this->expressCheckoutController->changeContext($customerId, $salesChannelContext);
        }

        $routeResponse = $this->handlePaymentMethodRoute->load($request, $salesChannelContext);

        return new JsonResponse($routeResponse->getObject());
    }

    #[Route(
        '/adyen/proxy-finalize-transaction',
        name: 'payment.adyen.proxy-finalize-transaction',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['GET']
    )]
    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $paymentToken = $request->get('_sw_payment_token');
        if ($this->paymentTokenValidator->validateToken($paymentToken)) {
            return $this->redirectToRoute(
                'payment.finalize.transaction',
                $request->query->all(),
            );
        }

        $transactionId = $request->get('transactionId');
        $orderId = $request->get('orderId') ?? '';
        $transaction = $this->adyenPaymentService->getPaymentTransactionStruct($transactionId, $salesChannelContext);
        $transactionState = $transaction->getOrderTransaction()->getStateMachineState();
        $transactionStateTechnicalName = $transactionState ?
            $transactionState->getTechnicalName() : OrderTransactionStates::STATE_FAILED;

        if ($transactionStateTechnicalName === OrderTransactionStates::STATE_FAILED ||
            $transactionStateTechnicalName === OrderTransactionStates::STATE_CANCELLED) {
            return $this->redirectToRoute(
                'frontend.account.edit-order.page',
                [
                    'orderId' => $orderId,
                    'error-code' => 'CHECKOUT__UNKNOWN_ERROR',
                ]
            );
        }

        return $this->redirectToRoute(
            'frontend.checkout.finish.page',
            ['orderId' => $orderId]
        );
    }

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
    */
    #[Route(
        '/adyen/proxy-payment-methods',
        name: 'payment.adyen.proxy-payment-methods',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['GET']
    )]
    public function paymentMethods(SalesChannelContext $context): JsonResponse
    {
        return $this->paymentController->getPaymentMethods($context);
    }

    #[Route(
        '/adyen/proxy-payment-status',
        name: 'payment.adyen.proxy-payment-status',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function paymentStatus(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->paymentController->getPaymentStatus($request, $context);
    }

    #[Route(
        '/adyen/proxy-payment-details',
        name: 'payment.adyen.proxy-payment-details',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function paymentDetails(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->paymentController->postPaymentDetails($request, $context);
    }

    #[Route(
        '/adyen/proxy-set-payment',
        name: 'payment.adyen.proxy-set-payment',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function setPaymentMethod(Request $request, SalesChannelContext $context): SetPaymentOrderRouteResponse
    {
        return $this->paymentController->updatePaymentMethod($request, $context);
    }

    #[Route(
        '/adyen/proxy-cancel-order-transaction',
        name: 'payment.adyen.proxy-cancel-order-transaction',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function cancelOrderTransaction(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->paymentController->cancelOrderTransaction($request, $context);
    }

    #[Route(
        '/adyen/proxy-check-balance',
        name: 'payment.adyen.proxy-check-balance',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function checkBalance(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->orderApiController->getPaymentMethodsBalance($context, $request);
    }

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
     */
    #[Route(
        '/adyen/proxy-create-adyen-order',
        name: 'payment.adyen.proxy-create-adyen-order',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function createAdyenOrder(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->orderApiController->createOrder($context, $request);
    }

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
     */
    #[Route(
        '/adyen/proxy-cancel-adyen-order',
        name: 'payment.adyen.proxy-cancel-adyen-order',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function cancelAdyenOrder(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->orderApiController->cancelOrder($context, $request);
    }

    #[Route(
        '/adyen/proxy-store-giftcard-state-data',
        name: 'payment.adyen.proxy-store-giftcard-state-data',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function storeGiftcardStateData(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->orderApiController->giftcardStateData($context, $request);
    }

    #[Route(
        '/adyen/proxy-remove-giftcard-state-data',
        name: 'payment.adyen.proxy-remove-giftcard-state-data',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function removeGiftcardStateData(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->orderApiController->deleteGiftCardStateData($context, $request);
    }

    #[Route(
        '/adyen/proxy-fetch-redeemed-giftcards',
        name: 'payment.adyen.proxy-fetch-redeemed-giftcards',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['GET']
    )]
    public function fetchRedeemedGiftcards(SalesChannelContext $context): JsonResponse
    {
        return $this->orderApiController->fetchRedeemedGiftcards($context);
    }

    #[Route(
        '/adyen/proxy-donate',
        name: 'payment.adyen.proxy-donate',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function donate(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->donateController->donate($request, $context);
    }

    #[Route(
        '/adyen/proxy-express-checkout-config',
        name: 'payment.adyen.proxy-express-checkout-config',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function getExpressCheckoutConfiguration(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        return $this->expressCheckoutController->getExpressCheckoutConfig($request, $salesChannelContext);
    }

    #[Route(
        '/adyen/proxy-express-checkout-update-paypal-order',
        name: 'payment.adyen.proxy-express-checkout-update-paypal-order',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function payPalUpdateOrder(
        Request             $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        return $this->expressCheckoutController->updatePayPalOrder($request, $salesChannelContext);
    }
}
