<?php

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
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaypalPaymentMethodHandler;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Adyen\Shopware\PaymentMethods\PaypalPaymentMethod;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Util\CheckoutStateDataValidator;
use Adyen\Shopware\Util\Currency;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class PaypalPaymentService.
 *
 * @package Adyen\Shopware\Service
 */
class PaypalPaymentService
{
    /**
     * Error codes that are safe to display to the shopper.
     *
     * @see https://docs.adyen.com/development-resources/error-codes
     */
    const  SAFE_ERROR_CODES = ['124'];

    /**
     * @param ClientService $clientService
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param NumberRangeValueGeneratorInterface $numberRangeValueGenerator
     * @param RequestStack $requestStack
     * @param LoggerInterface $logger
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param SalesChannelRepository $salesChannelRepository
     * @param CartOrderRoute $cartOrderRoute
     * @param HandlePaymentMethodRoute $handlePaymentMethodRoute
     * @param ExpressCheckoutService $expressCheckoutService
     * @param CartService $cartService
     */
    public function __construct(
        private readonly ClientService $clientService,
        private readonly CheckoutStateDataValidator $checkoutStateDataValidator,
        private readonly ConfigurationService $configurationService,
        private readonly Currency $currency,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly PaymentResponseHandler $paymentResponseHandler,
        private readonly SalesChannelRepository $salesChannelRepository,
        private readonly CartOrderRoute $cartOrderRoute,
        private readonly HandlePaymentMethodRoute $handlePaymentMethodRoute,
        private readonly ExpressCheckoutService $expressCheckoutService,
        private readonly CartService $cartService
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
            $this->paymentResponseHandler
                ->handleShopwareApis($order->getTransactions()->first(), $context, [$paymentDetailsResponse]);
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
     * @throws JsonException
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

        $paymentRequest = new IntegrationPaymentRequest($stateData);
        $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
        $paymentMethod = $this->getPaymentMethodFromStateData($stateData);

        $this->setPaymentMethod($paymentMethod, PaypalPaymentMethodHandler::getPaymentMethodCode());
        $amount = $this->getAmountFromCart($cart, $context, false);
        $paymentRequest->setAmount($amount);
        $paymentRequest->setMerchantAccount($this->getMerchantAccountForContext($context));
        $paymentRequest->setReference($this->generateNextOrderNumberForContext($context));
        $paymentRequest->setReturnUrl($this->salesChannelRepository->getCurrentDomainUrl($context));

        $response = $this->paymentsCall($context, $paymentRequest);

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
        $paymentRequest = new IntegrationPaymentRequest($stateData);
        //Validate state.data for payment and build request object
        $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);

        $paymentMethod = $this->getPaymentMethodFromStateData($stateData);
        $this->setPaymentMethod($paymentMethod, PaypalPaymentMethodHandler::getPaymentMethodCode());
        $paymentRequest->setPaymentMethod($paymentMethod);

        $paymentRequest->setAmount($this->getAmountFromCart($cart, $context));
        $paymentRequest->setMerchantAccount($this->getMerchantAccountForContext($context));
        $paymentRequest->setReference($this->generateNextOrderNumberForContext($context));
        $paymentRequest->setReturnUrl($this->salesChannelRepository->getCurrentDomainUrl($context));
        $paymentRequest->setDeliveryAddress($this->getDeliveryAddressFromContext($context));
        $paymentRequest->setBillingAddress($this->getBillingAddressFromContext($context));
        $paymentRequest->setShopperName($this->getShopperNameFromContext($context));
        $paymentRequest->setShopperEmail($this->getShopperEmailFromContext($context));
        $paymentRequest->setTelephoneNumber($this->getShopperPhoneNumberFromContext($context));
        $paymentRequest->setCountryCode($this->getCountryCodeFromContext($context));
        $paymentRequest->setShopperLocale($this->getShopperLocaleFromContext($context));
        $shopperIp = $this->getShopperIpFromContext($context);
        $shopperIp && $paymentRequest->setShopperIP($shopperIp);
        $shopperReference = $this->getShopperReferenceFromContext($context);
        $shopperReference && $paymentRequest->setShopperReference($shopperReference);
        $paymentRequest->setOrigin($this->getOriginFromContext($context));
        $paymentRequest->setAdditionaldata(['allow3DS2' => true]);
        $paymentRequest->setChannel('Web');
        $paymentRequest->setShopperInteraction(AbstractPaymentMethodHandler::SHOPPER_INTERACTION_ECOMMERCE);

        $response = $this->paymentsCall($context, $paymentRequest);

        return $response->toArray();
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getOriginFromContext(SalesChannelContext $context): string
    {
        return $this->salesChannelRepository->getCurrentDomainUrl($context);
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return ?string
     */
    public function getShopperReferenceFromContext(SalesChannelContext $context): ?string
    {
        return $context->getCustomer()?->getId();
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return ?string
     */
    protected function getShopperIpFromContext(SalesChannelContext $context): ?string
    {
        return $context->getCustomer()?->getRemoteAddress();
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getShopperLocaleFromContext(SalesChannelContext $context): string
    {
        return $this->salesChannelRepository->getSalesChannelLocale($context);
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getCountryCodeFromContext(SalesChannelContext $context): string
    {
        return $context->getCustomer()?->getActiveBillingAddress()?->getCountry()?->getIso() ?? '';
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getShopperPhoneNumberFromContext(SalesChannelContext $context): string
    {
        return $context->getShippingLocation()->getAddress()?->getPhoneNumber() ?? '';
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getShopperEmailFromContext(SalesChannelContext $context): string
    {
        return $context->getCustomer()?->getEmail() ?? '';
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return Name
     */
    protected function getShopperNameFromContext(SalesChannelContext $context): Name
    {
        $shopperFirstName = $context->getCustomer()?->getFirstName();
        $shopperLastName = $context->getCustomer()?->getLastName();

        $shopperName = new Name();
        $shopperName->setFirstName($shopperFirstName ?? '');
        $shopperName->setLastName($shopperLastName ?? '');

        return $shopperName;
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return BillingAddress
     */
    protected function getBillingAddressFromContext(SalesChannelContext $context): BillingAddress
    {
        return $this->mapContextToAddress($context, new BillingAddress());
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return DeliveryAddress
     */
    protected function getDeliveryAddressFromContext(SalesChannelContext $context): DeliveryAddress
    {
        return $this->mapContextToAddress($context, new DeliveryAddress());
    }

    /**
     * @template T of BillingAddress|DeliveryAddress
     * @param SalesChannelContext $context
     * @param BillingAddress|DeliveryAddress $addressObject
     *
     * @return BillingAddress|DeliveryAddress
     */
    private function mapContextToAddress(
        SalesChannelContext $context,
        BillingAddress|DeliveryAddress $addressObject
    ): BillingAddress|DeliveryAddress {
        $shippingAddress = $context->getShippingLocation()->getAddress();
        $stateCode = $shippingAddress?->getCountryState()?->getShortCode() ?? 'n/a';

        $streetData = $this->getSplitStreetAddressHouseNumber(
            $shippingAddress?->getStreet() ?? ''
        );

        $addressObject->setStreet($streetData['street']);
        $addressObject->setHouseNumberOrName($streetData['houseNumber']);
        $addressObject->setPostalCode($shippingAddress?->getZipcode());
        $addressObject->setCity($shippingAddress?->getCity());
        $addressObject->setStateOrProvince($stateCode);
        $addressObject->setCountry($shippingAddress?->getCountry()?->getIso());

        return $addressObject;
    }

    /**
     * @param string $address
     *
     * @return array|string[]
     */
    protected function getSplitStreetAddressHouseNumber(string $address): array
    {
        $patterns = [
            'streetFirst' => '/(?<streetName>[\w\W]+)\s+(?<houseNumber>[\d-]{1,10}(?:\s?\w{1,3})?)$/m',
            'numberFirst' => '/^(?<houseNumber>[\d-]{1,10}(?:\s?\w{1,3})?)\s+(?<streetName>[\w\W]+)/m'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $address, $matches)) {
                return [
                    'street' => trim($matches['streetName']),
                    'houseNumber' => trim($matches['houseNumber'])
                ];
            }
        }

        return [
            'street' => $address,
            'houseNumber' => 'N/A'
        ];
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param IntegrationPaymentRequest $request
     *
     * @return PaymentResponse
     *
     * @throws AdyenException
     */
    protected function paymentsCall(
        SalesChannelContext $salesChannelContext,
        IntegrationPaymentRequest $request
    ): PaymentResponse {
        try {
            $this->clientService->logRequest(
                $request->toArray(),
                Client::API_CHECKOUT_VERSION,
                '/payments',
                $salesChannelContext->getSalesChannelId()
            );

            $response = $this->getPaymentApiServiceFromContext($salesChannelContext)->payments($request);

            $this->clientService->logResponse(
                $response->toArray(),
                $salesChannelContext->getSalesChannelId()
            );

            return $response;
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request.  %s",
                $exception->getMessage()
            );
            $this->displaySafeErrorMessages($exception);
            $this->logger->error($message);

            throw $exception;
        }
    }


    /**
     * @param AdyenException $exception
     *
     * @return void
     */
    protected function displaySafeErrorMessages(AdyenException $exception): void
    {
        if ('validation' === $exception->getErrorType()
            && in_array($exception->getAdyenErrorCode(), self::SAFE_ERROR_CODES)) {
            $this->requestStack->getSession()->getFlashBag()->add('warning', $exception->getMessage());
        }
    }

    /**
     * @param array $stateData
     *
     * @return CheckoutPaymentMethod
     */
    protected function getPaymentMethodFromStateData(array $stateData): CheckoutPaymentMethod
    {
        return new CheckoutPaymentMethod($stateData['paymentMethod'] ?? null);
    }

    /**
     * @param CheckoutPaymentMethod $paymentMethod
     * @param string $paymentMethodCode
     *
     * @return void
     */
    protected function setPaymentMethod(CheckoutPaymentMethod $paymentMethod, string $paymentMethodCode): void
    {
        $paymentMethod->setType($paymentMethodCode);
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @param bool $withShipping
     *
     * @return Amount
     */
    protected function getAmountFromCart(Cart $cart, SalesChannelContext $context, bool $withShipping = true): Amount
    {
        $price = $cart->getPrice()->getTotalPrice();

        if (!$withShipping) {
            $price -= $cart->getShippingCosts()->getTotalPrice();
        }

        $orderAmount = $this->currency->sanitize(
            $price,
            $context->getCurrency()->getIsoCode()
        );
        $amount = new Amount();
        $amount->setCurrency($context->getCurrency()->getIsoCode());
        $amount->setValue($orderAmount);

        return $amount;
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getMerchantAccountForContext(SalesChannelContext $context): string
    {
        return $this->configurationService->getMerchantAccount($context->getSalesChannel()->getId());
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
