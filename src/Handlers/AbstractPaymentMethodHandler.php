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
use Adyen\Service\Builder\Address;
use Adyen\Service\Builder\Browser;
use Adyen\Service\Builder\Customer;
use Adyen\Service\Builder\Payment;
use Adyen\Service\Builder\OpenInvoice;
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentRequestService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Storefront\Controller\RedirectResultController;
use Adyen\Util\Currency;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PreparedPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\PreparedPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CapturePreparedPaymentException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\ValidatePreparedPaymentException;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Adyen\Shopware\Exception\CurrencyNotFoundException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

abstract class AbstractPaymentMethodHandler
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
     * @var OpenInvoice
     */
    protected $openInvoiceBuilder;

    /**
     * @var Currency
     */
    protected $currency;

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
     * AbstractPaymentMethodHandler constructor.
     * @param ClientService $clientService
     * @param PaymentStateDataService $paymentStateDataService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param ResultHandler $resultHandler
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param OpenInvoice $openInvoiceBuilder
     * @param Currency $currency
     * @param PaymentRequestService $paymentRequestService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ClientService $clientService,
        PaymentStateDataService $paymentStateDataService,
        SalesChannelRepository $salesChannelRepository,
        PaymentResponseHandler $paymentResponseHandler,
        ResultHandler $resultHandler,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        OpenInvoice $openInvoiceBuilder,
        Currency $currency,
        PaymentRequestService $paymentRequestService,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->resultHandler = $resultHandler;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->openInvoiceBuilder = $openInvoiceBuilder;
        $this->currency = $currency;
        $this->paymentRequestService = $paymentRequestService;
        $this->logger = $logger;
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

        try {
            $request = $this->preparePaymentsRequest($salesChannelContext, $transaction, $stateData);
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

        $result = $this->paymentResponseHandler->handlePaymentResponse($response, $transaction->getOrderTransaction());

        try {
            $this->paymentResponseHandler->handleShopwareApis($transaction, $salesChannelContext, $result);
        } catch (PaymentCancelledException $exception) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw new AsyncPaymentFinalizeException($transactionId, $exception->getMessage());
        }

        // Payment had no error, continue the process
        return new RedirectResponse($this->paymentRequestService->getAdyenReturnUrl($this->getReturnUrlQuery($transaction)));
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

    public function validate(Cart $cart, RequestDataBag $requestDataBag, SalesChannelContext $context): Struct
    {
        return new ArrayStruct(['valid']);
    }

    public function capture(PreparedPaymentTransactionStruct $transaction, RequestDataBag $requestDataBag, SalesChannelContext $context, Struct $preOrderPaymentStruct): void
    {
        // TODO: Implement capture() method.
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param string|null $stateData
     * @return array
     */
    protected function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
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
        $lineItems = null;
        if (static::$isOpenInvoice) {
            $lineItems = $this->getLineItems($transaction, $salesChannelContext);
        }

        $request = $this->paymentRequestService->buildPaymentRequest(
            $request,
            $salesChannelContext,
            static::class,
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $transaction->getOrder()->getOrderNumber(),
            $this->getReturnUrlQuery($transaction),
            $lineItems
        );

        // Remove the used state.data
        if (isset($stateDataEntity)) {
            $this->paymentStateDataService->deletePaymentStateData($stateDataEntity);
        }

        return $request;
    }

    private function getLineItems(SyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext)
    {
        $orderLines = $transaction->getOrder()->getLineItems();
        $lineItems = [];
        foreach ($orderLines->getElements() as $orderLine) {
            //Getting line price
            $price = $orderLine->getPrice();

            // Skip promotion line items.
            if (empty($orderLine->getProductId()) && $orderLine->getType() === self::PROMOTION) {
                continue;
            }

            $product = $this->paymentRequestService->getProduct($orderLine->getProductId(), $salesChannelContext->getContext());
            $productName = $product->getTranslation('name');
            $productNumber = $product->getProductNumber();

            //Getting line tax amount and rate
            $lineTax = $price->getCalculatedTaxes()->getAmount() / $orderLine->getQuantity();
            $taxRate = $price->getCalculatedTaxes()->first();
            if (!empty($taxRate)) {
                $taxRate = $taxRate->getTaxRate();
            } else {
                $taxRate = 0;
            }

            $currency = $this->paymentRequestService->getCurrency(
                $transaction->getOrder()->getCurrencyId(),
                $salesChannelContext->getContext()
            );
            //Building open invoice line
            $lineItems[] = $this->openInvoiceBuilder->buildOpenInvoiceLineItem(
                $productName,
                $this->currency->sanitize(
                    $price->getUnitPrice() -
                    ($transaction->getOrder()->getTaxStatus() == 'gross' ? $lineTax : 0),
                    $currency->getIsoCode()
                ),
                $this->currency->sanitize(
                    $lineTax,
                    $currency->getIsoCode()
                ),
                $taxRate * 100,
                $orderLine->getQuantity(),
                '',
                $productNumber
            );
        }

        return $lineItems;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @return array|int|string|null
     */
    public function getReturnUrlQuery(AsyncPaymentTransactionStruct $transaction)
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
        return $returnUrlQuery;
    }
}
