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

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Model\Checkout\BillingAddress;
use Adyen\Model\Checkout\DeliveryAddress;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\CheckoutPaymentMethod;
use Adyen\Model\Checkout\Name;
use Adyen\Model\Checkout\PaymentDetailsRequest;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Exception\ResolveCountryException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaypalPaymentMethodHandler;
use Adyen\Shopware\PaymentMethods\PaypalPaymentMethod;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Exception;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Class PaypalPaymentService.
 *
 * @package Adyen\Shopware\Service
 */
readonly class PaypalPaymentService
{
    /**
     * @param ClientService $clientService
     * @param NumberRangeValueGeneratorInterface $numberRangeValueGenerator
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param SalesChannelRepository $salesChannelRepository
     * @param CartOrderRoute $cartOrderRoute
     * @param HandlePaymentMethodRoute $handlePaymentMethodRoute
     * @param ExpressCheckoutService $expressCheckoutService
     * @param CartService $cartService
     * @param PaymentRequestService $paymentRequestService
     */
    public function __construct(
        private readonly ClientService $clientService,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly PaymentResponseHandler $paymentResponseHandler,
        private readonly SalesChannelRepository $salesChannelRepository,
        private readonly CartOrderRoute $cartOrderRoute,
        private readonly HandlePaymentMethodRoute $handlePaymentMethodRoute,
        private readonly ExpressCheckoutService $expressCheckoutService,
        private readonly CartService $cartService,
        private readonly RouterInterface $router,
        private PaymentRequestService $paymentRequestService
    ) {
    }

    /**
     * Finalize Paypal payment and creates order on Shopware.
     *
     * @param SalesChannelContext $context
     * @param Cart $cart
     * @param Request $request
     * @param RequestDataBag $dataBag
     * @param array $stateData
     *
     * @return string Order ID of newly created order
     *
     * @throws AdyenException
     */
    public function finalizePaypalPayment(
        SalesChannelContext $context,
        Cart $cart,
        Request $request,
        RequestDataBag $dataBag,
        array $stateData
    ): string {
        $paymentDetailsResponse = $this->getPaymentApiServiceFromContext($context)
            ->paymentsDetails(new PaymentDetailsRequest($stateData));

        $cart->addExtension(
            OrderConverter::ORIGINAL_ORDER_NUMBER,
            new IdStruct($paymentDetailsResponse->getMerchantReference())
        );

        $order = $this->cartOrderRoute->order($cart, $context, $dataBag)->getOrder();
        $request->request->set('orderId', $order->getId());
        $this->handlePaymentMethodRoute->load($request, $context);

        try {
            $this->paymentResponseHandler
                ->handlePaymentResponse($paymentDetailsResponse, $order->getTransactions()->first());

            $returnUrl = $this->router->generate(
                'frontend.account.edit-order.page',
                ['orderId' => $order->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $paymentTransaction = new AsyncPaymentTransactionStruct(
                $order->getTransactions()->first(),
                $order,
                $returnUrl
            );

            $this->paymentResponseHandler
                ->handleShopwareApis($paymentTransaction, $context, [$paymentDetailsResponse]);
        } catch (PaymentCancelledException $exception) {
            throw PaymentException::customerCanceled(
                $order->getTransactions()->first()->getId(),
                $exception->getMessage()
            );
        } catch (PaymentFailedException $exception) {
            throw PaymentException::asyncFinalizeInterrupted(
                $order->getTransactions()->first()->getId(),
                $exception->getMessage()
            );
        }

        return $order->getId();
    }

    /**
     * Finalize Paypal payment and creates order on Shopware.
     *
     * @param string $cartToken
     * @param SalesChannelContext $context
     * @param Request $request
     * @param RequestDataBag $dataBag
     * @param array $stateData
     * @param array $newAddress
     *
     * @return string Order ID of newly created order
     *
     * @throws AdyenException
     * @throws ResolveCountryException
     */
    public function finalizeExpressPaypalPayment(
        string $cartToken,
        SalesChannelContext $context,
        Request $request,
        RequestDataBag $dataBag,
        array $stateData,
        array $newAddress
    ): string {
        $oldContext = $context;
        $customerID = null;

        if ($context->getPaymentMethod()->getName() !== PaypalPaymentMethod::PAYPAL_PAYMENT_METHOD_NAME) {
            $context = $this->expressCheckoutService->getSalesChannelContext($cartToken, $context->getSalesChannelId());
        }

        $cart = $this->cartService->getCart($cartToken, $context, false);
        if (!empty($newAddress)) {
            $context = $this->expressCheckoutService->createCustomerAndUpdateContext(
                $context,
                $cartToken,
                $newAddress,
            );

            $customerID = $context->getCustomer()->getId();
        }

        $res = $this->finalizePaypalPayment($context, $cart, $request, $dataBag, $stateData);

        $customerID && $this->expressCheckoutService->changeContext(
            $customerID,
            $oldContext
        );

        return $res;
    }

    /**
     * @param array $cartData
     * @param SalesChannelContext $context
     * @param SalesChannelContext $updatedContext
     *
     * @param array $stateData
     *
     * @return array
     *
     * @throws AdyenException
     * @throws Exception
     */
    public function createPayPalExpressPaymentRequest(
        array $cartData,
        SalesChannelContext $context,
        SalesChannelContext $updatedContext,
        array $stateData = []
    ): array {
        if ($context->getPaymentMethod()->getName() !== PaypalPaymentMethod::PAYPAL_PAYMENT_METHOD_NAME) {
            $this->expressCheckoutService->changeContext(
                $updatedContext->getCustomerId(),
                $updatedContext
            );
        }

        /** @var Cart $cart */
        $cart = $cartData['cart'];
        $customer = $context->getCustomer();

        if ($customer && !$customer->getGuest()) {
            return $this->createPayPalPaymentRequest($cart, $updatedContext, $stateData);
        }

        $paymentRequest = $this->paymentRequestService->buildPaymentRequestFromCart(
            salesChannelContext: $context,
            cart: $cart,
            returnUrl: $this->salesChannelRepository->getCurrentDomainUrl($context),
            orderReference: $this->generateNextOrderNumberForContext($context),
            paymentMethodCode: PaypalPaymentMethodHandler::getPaymentMethodCode(),
            stateData: $stateData,
            includeShipping: false
        );

        $response = $this->paymentRequestService->executePayment($context, $paymentRequest);

        return $response->toArray();
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $context
     *
     * @param array $stateData
     *
     * @return array
     *
     * @throws AdyenException
     */
    public function createPayPalPaymentRequest(Cart $cart, SalesChannelContext $context, array $stateData = []): array
    {
        $paymentRequest = $this->paymentRequestService->buildPaymentRequestFromCart(
            salesChannelContext: $context,
            cart: $cart,
            returnUrl: $this->salesChannelRepository->getCurrentDomainUrl($context),
            orderReference: $this->generateNextOrderNumberForContext($context),
            paymentMethodCode: PaypalPaymentMethodHandler::getPaymentMethodCode(),
            stateData: $stateData,
            includeShipping: true
        );

        $response = $this->paymentRequestService->executePayment($context, $paymentRequest);

        return $response->toArray();
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function generateNextOrderNumberForContext(SalesChannelContext $context): string
    {
        return $this->numberRangeValueGenerator->getValue(
            OrderDefinition::ENTITY_NAME,
            $context->getContext(),
            $context->getSalesChannel()->getId()
        );
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return PaymentsApi
     *
     * @throws AdyenException
     */
    private function getPaymentApiServiceFromContext(SalesChannelContext $context): PaymentsApi
    {
        return new PaymentsApi(
            $this->clientService->getClient($context->getSalesChannelId())
        );
    }
}
