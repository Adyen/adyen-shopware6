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

namespace Adyen\Shopware\Handlers;

use Adyen\AdyenException;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentRequestService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Storefront\Controller\RedirectResultController;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PreparedPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\PreparedPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CapturePreparedPaymentException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\ValidatePreparedPaymentException;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

abstract class AbstractPaymentMethodHandler implements
    AsynchronousPaymentHandlerInterface,
    PreparedPaymentHandlerInterface
{

    const PROMOTION = 'promotion';
    /**
     * Error codes that are safe to display to the shopper.
     * @see https://docs.adyen.com/development-resources/error-codes
     */
    const SAFE_ERROR_CODES = ['124'];

    public static $isOpenInvoice = false;
    public static $isGiftCard = false;

    /**
     * @var ClientService
     */
    protected $clientService;

    /**
     * @var CaptureService
     */
    private $captureService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var PaymentStateDataService
     */
    protected $paymentStateDataService;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var SalesChannelRepository
     */
    protected $salesChannelRepository;

    /**
     * @var PaymentResponseHandler
     */
    protected $paymentResponseHandler;

    /**
     * @var PaymentResponseHandlerResult
     */
    protected $paymentResponseHandlerResult;

    /**
     * @var ResultHandler
     */
    protected $resultHandler;

    /**
     * @var OrderTransactionStateHandler
     */
    protected $orderTransactionStateHandler;

    /**
     * @var PaymentRequestService
     */
    protected $paymentRequestService;

    /**
     * @var CsrfTokenManagerInterface
     */
    private $csrfTokenManager;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var Currency
     */
    private $currency;

    /**
     * AbstractPaymentMethodHandler constructor.
     * @param ClientService $clientService
     * @param CaptureService $captureService
     * @param Currency $currency
     * @param ConfigurationService $configurationService
     * @param PaymentStateDataService $paymentStateDataService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param ResultHandler $resultHandler
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param RouterInterface $router
     * @param PaymentResponseHandlerResult $paymentResponseHandlerResult
     * @param PaymentResponseService $paymentResponseService
     * @param CsrfTokenManagerInterface $csrfTokenManager
     * @param PaymentRequestService $paymentRequestService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ClientService $clientService,
        CaptureService $captureService,
        Currency $currency,
        ConfigurationService $configurationService,
        PaymentStateDataService $paymentStateDataService,
        SalesChannelRepository $salesChannelRepository,
        PaymentResponseHandler $paymentResponseHandler,
        ResultHandler $resultHandler,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        RouterInterface $router,
        PaymentResponseHandlerResult $paymentResponseHandlerResult,
        PaymentResponseService $paymentResponseService,
        CsrfTokenManagerInterface $csrfTokenManager,
        PaymentRequestService $paymentRequestService,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->configurationService = $configurationService;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
        $this->resultHandler = $resultHandler;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->router = $router;
        $this->paymentResponseService = $paymentResponseService;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->paymentRequestService = $paymentRequestService;
        $this->logger = $logger;
        $this->captureService = $captureService;
        $this->currency = $currency;
    }

    abstract public static function getPaymentMethodCode();

    public static function getBrand(): ?string
    {
        return null;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws PaymentProcessException|AdyenException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $checkoutService = new CheckoutService(
            $this->clientService->getClient($salesChannelContext->getSalesChannel()->getId())
        );
        $stateData = $dataBag->get('stateData');
        $returnUrl = $this->getAdyenReturnUrl($transaction);

        try {
            $request = $this->preparePaymentsRequest($salesChannelContext, $transaction, $returnUrl, $stateData);
        } catch (AsyncPaymentProcessException $exception) {
            $this->logger->error($exception->getMessage());
            throw $exception;
        } catch (\Exception $exception) {
            $message = sprintf(
                "There was an error with the payment method. Order number: %s Missing data: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        try {
            $response = $checkoutService->payments($request);
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request. Order number %s: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->paymentRequestService->displaySafeErrorMessages($exception);
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if (empty($orderNumber)) {
            $message = 'Order number is missing';
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $result = $this->paymentResponseHandler
            ->handlePaymentResponse($response, $transaction->getOrderTransaction()->getId());

        try {
            $this->paymentResponseHandler->handleShopwareApis($transaction, $salesChannelContext, $result);
        } catch (PaymentCancelledException $exception) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw new AsyncPaymentFinalizeException($transactionId, $exception->getMessage());
        }

        // Payment had no error, continue the process
        return new RedirectResponse($returnUrl);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        try {
            $this->resultHandler->processResult($transaction, $request, $salesChannelContext);
        } catch (PaymentCancelledException $exception) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw new AsyncPaymentFinalizeException($transactionId, $exception->getMessage());
        }
    }

    /**
     * @param Cart $cart
     * @param RequestDataBag $requestDataBag
     * @param SalesChannelContext $context
     * @return Struct
     * @throws ValidatePreparedPaymentException
     */
    public function validate(Cart $cart, RequestDataBag $requestDataBag, SalesChannelContext $context): Struct
    {
        if ($this->configurationService->usesPreparedPaymentFlow($context->getSalesChannelId())) {
            $paymentReference = $requestDataBag->get('paymentReference');
            if (empty($paymentReference)) {
                throw new ValidatePreparedPaymentException('Error: Payment reference is required.');
            }
            $response = $this->paymentResponseService->getWithPaymentReference($paymentReference);
            if (empty($response)) {
                throw new ValidatePreparedPaymentException('Error: Payment result not found.');
            }

            $valid = $response->getResultCode() === PaymentResponseHandler::AUTHORISED;

            return new ArrayStruct(['isValid' => $valid]);
        }

        return new ArrayStruct([]);
    }

    /**
     * @param PreparedPaymentTransactionStruct $transaction
     * @param RequestDataBag $requestDataBag
     * @param SalesChannelContext $context
     * @param Struct $preOrderPaymentStruct
     * @return void
     * @throws PaymentProcessException
     */
    public function capture(
        PreparedPaymentTransactionStruct $transaction,
        RequestDataBag $requestDataBag,
        SalesChannelContext $context,
        Struct $preOrderPaymentStruct
    ): void {
        if (!$this->configurationService->usesPreparedPaymentFlow($context->getSalesChannelId())) {
            return;
        }
        if (!$preOrderPaymentStruct->get('isValid')) {
            return;
        }

        $paymentReference = $requestDataBag->get('paymentReference');
        if (empty($paymentReference)) {
            throw new CapturePreparedPaymentException(
                $transaction->getOrderTransaction()->getId(),
                'Error: Payment reference is required.'
            );
        }
        $paymentResponse = $this->paymentResponseService->getWithPaymentReference($paymentReference);
        try {
            $result = $this->paymentResponseHandlerResult->createFromPaymentResponse($paymentResponse);
            $this->paymentResponseHandler->handleShopwareApis($transaction, $context, $result);
        } catch (PaymentException $e) {
            $this->logger->error('Error occurred in updating order transaction: ' . $e->getMessage());
            throw new CapturePreparedPaymentException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred. Please contact administrator.'
            );
        }

        if ($this->captureService
            ->requiresManualCapture($transaction->getOrderTransaction()->getPaymentMethod()->getHandlerIdentifier())) {
            return;
        }

        $currency = $transaction->getOrder()->getCurrency()->getIsoCode();
        $captureAmount = $this->currency->sanitize($transaction->getOrder()->getAmountTotal(), $currency);

        try {
            $this->captureService->capture(
                $context->getContext(),
                $transaction->getOrder()->getOrderNumber(),
                $captureAmount,
                true
            );
        } catch (CaptureException $e) {
            $this->logger->error($e->getMessage());
            throw new CapturePreparedPaymentException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred. Please contact administrator.'
            );
        }
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param string $returnUrl
     * @param string|null $stateData
     * @return array
     */
    protected function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        string $returnUrl,
        ?string $stateData = null
    ): array {
        $request = [];

        if ($stateData) {
            $request = json_decode($stateData, true);
        } else {
            // Get state.data using the context token
            $stateDataEntity = $this->paymentStateDataService->getPaymentStateDataFromContextToken(
                $salesChannelContext->getToken()
            );
            if ($stateDataEntity) {
                $request = json_decode($stateDataEntity->getStateData(), true);
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Invalid payment state data.'
            );
        }

        $currency = $this->paymentRequestService->getCurrency(
            $transaction->getOrder()->getCurrencyId(),
            $salesChannelContext->getContext()
        );
        $lineItems = $this->paymentRequestService->getLineItems(
            $transaction->getOrder()->getLineItems(),
            $salesChannelContext->getContext(),
            $currency,
            $transaction->getOrder()->getTaxStatus()
        );

        $request = $this->paymentRequestService->buildPaymentRequest(
            $request,
            $salesChannelContext,
            static::class,
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $transaction->getOrder()->getOrderNumber(),
            $returnUrl,
            $lineItems
        );

        // Remove the used state.data
        if (isset($stateDataEntity)) {
            $this->paymentStateDataService->deletePaymentStateData($stateDataEntity);
        }

        return $request;
    }

    /**
     * Creates the Adyen Redirect Result URL with the same query as the original return URL
     * Fixes the CSRF validation bug: https://issues.shopware.com/issues/NEXT-6356
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @return string
     */
    private function getAdyenReturnUrl(AsyncPaymentTransactionStruct $transaction): string
    {
        // Parse the original return URL to retrieve the query parameters
        $returnUrlQuery = parse_url($transaction->getReturnUrl(), PHP_URL_QUERY);

        // In case the URL is malformed it cannot be parsed
        if (false === $returnUrlQuery) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Return URL is malformed'
            );
        }

        // Generate the custom Adyen endpoint to receive the redirect from the issuer page
        $adyenReturnUrl = $this->router->generate(
            'payment.adyen.redirect_result',
            [
                RedirectResultController::CSRF_TOKEN => $this->csrfTokenManager->getToken(
                    'payment.finalize.transaction'
                )->getValue()
            ],
            RouterInterface::ABSOLUTE_URL
        );

        // Create the adyen redirect result URL with the same query as the original return URL
        return $adyenReturnUrl . '&' . $returnUrlQuery;
    }
}
