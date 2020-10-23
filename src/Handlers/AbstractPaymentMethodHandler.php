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
use Adyen\Shopware\Exception\PaymentException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Storefront\Controller\RedirectResultController;
use Adyen\Util\Currency;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Adyen\Shopware\Exception\CurrencyNotFoundException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

abstract class AbstractPaymentMethodHandler
{

    const PROMOTION = 'promotion';

    protected static $isOpenInvoice = false;

    /**
     * @var CheckoutService
     */
    protected $checkoutService;

    /**
     * @var Browser
     */
    protected $browserBuilder;

    /**
     * @var Address
     */
    protected $addressBuilder;

    /**
     * @var Payment
     */
    protected $paymentBuilder;

    /**
     * @var OpenInvoice
     */
    protected $openInvoiceBuilder;

    /**
     * @var Currency
     */
    protected $currency;

    /**
     * @var ConfigurationService
     */
    protected $configurationService;

    /**
     * @var Customer
     */
    protected $customerBuilder;

    /**
     * @var CheckoutStateDataValidator
     */
    protected $checkoutStateDataValidator;

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
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var CsrfTokenManagerInterface
     */
    protected $csrfTokenManager;

    /**
     * @var EntityRepositoryInterface
     */
    protected $currencyRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $productRepository;

    /**
     * CardsPaymentMethodHandler constructor.
     *
     * @param ConfigurationService $configurationService
     * @param CheckoutService $checkoutService
     * @param Browser $browserBuilder
     * @param Address $addressBuilder
     * @param Payment $paymentBuilder
     * @param OpenInvoice $openInvoiceBuilder
     * @param Currency $currency
     * @param Customer $customerBuilder
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStateDataService $paymentStateDataService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param ResultHandler $resultHandler
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param RouterInterface $router
     * @param CsrfTokenManagerInterface $csrfTokenManager
     * @param LoggerInterface $logger
     * @param EntityRepositoryInterface $currencyRepository
     */
    public function __construct(
        ConfigurationService $configurationService,
        CheckoutService $checkoutService,
        Browser $browserBuilder,
        Address $addressBuilder,
        Payment $paymentBuilder,
        OpenInvoice $openInvoiceBuilder,
        Currency $currency,
        Customer $customerBuilder,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStateDataService $paymentStateDataService,
        SalesChannelRepository $salesChannelRepository,
        PaymentResponseHandler $paymentResponseHandler,
        ResultHandler $resultHandler,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        RouterInterface $router,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->checkoutService = $checkoutService;
        $this->browserBuilder = $browserBuilder;
        $this->addressBuilder = $addressBuilder;
        $this->openInvoiceBuilder = $openInvoiceBuilder;
        $this->currency = $currency;
        $this->configurationService = $configurationService;
        $this->customerBuilder = $customerBuilder;
        $this->paymentBuilder = $paymentBuilder;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->resultHandler = $resultHandler;
        $this->logger = $logger;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->router = $router;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->currencyRepository = $currencyRepository;
        $this->productRepository = $productRepository;
    }

    abstract public static function getPaymentMethodCode();

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $this->checkoutService->startClient($salesChannelContext->getSalesChannel()->getId());
        try {
            $request = $this->preparePaymentsRequest($salesChannelContext, $transaction);
        } catch (Exception $exception) {
            $message = sprintf(
                "There was an error with the payment method. Order number: %s Missing data: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        try {
            $response = $this->checkoutService->payments($request);
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request. Order number %s: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );

            $this->logger->error($message);

            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if (empty($orderNumber)) {
            $message = 'Order number is missing';
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $result = $this->paymentResponseHandler->handlePaymentResponse($response, $orderNumber, $salesChannelContext);

        try {
            $this->paymentResponseHandler->handleShopwareApis($transaction, $salesChannelContext, $result);
        } catch (PaymentCancelledException $exception) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw new AsyncPaymentFinalizeException($transactionId, $exception->getMessage());
        }

        // Payment had no error, continue the process
        return new RedirectResponse($transaction->getReturnUrl());
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $this->checkoutService->startClient($salesChannelContext->getSalesChannel()->getId());
        try {
            $this->resultHandler->processResult($transaction, $request, $salesChannelContext);
        } catch (PaymentCancelledException $exception) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw new AsyncPaymentFinalizeException($transactionId, $exception->getMessage());
        }
    }

    /**
     * @param string $address
     * @return array
     */
    public function splitStreetAddressHouseNumber(string $address): array
    {
        return [
            'street' => $address,
            'houseNumber' => 'N/A'
        ];
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @return array
     */
    public function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction
    ) {
        //Get state.data using the context token
        $stateData = $this->paymentStateDataService->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        );

        //Generate returnUrl
        $returnUrl = $transaction->getReturnUrl();

        if ($stateData) {
            $request = json_decode($stateData->getStateData(), true);
        } else {
            $request = [];
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Invalid payment state data.'
            );
        }

        if (!empty($request['additionalData'])) {
            $stateDataAdditionalData = $request['additionalData'];
        }

        //Validate state.data for payment and build request object
        $request = $this->checkoutStateDataValidator->getValidatedAdditionalData($request);

        //Setting payment method type if not present in statedata
        if (empty($request['paymentMethod']['type'])) {
            $paymentMethodType = static::getPaymentMethodCode();
        } else {
            $paymentMethodType = $request['paymentMethod']['type'];
        }

        //Setting browser info if not present in statedata
        if (empty($request['browserInfo']['acceptHeader'])) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'];
        } else {
            $acceptHeader = $request['browserInfo']['acceptHeader'];
        }
        if (empty($request['browserInfo']['userAgent'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $userAgent = $request['browserInfo']['userAgent'];
        }

        //Setting delivery address info if not present in statedata
        if (empty($request['deliveryAddress'])) {
            if ($salesChannelContext->getShippingLocation()->getAddress()->getCountryState()) {
                $shippingState = $salesChannelContext->getShippingLocation()
                    ->getAddress()->getCountryState()->getShortCode();
            } else {
                $shippingState = '';
            }

            $shippingStreetAddress = $this->splitStreetAddressHouseNumber(
                $salesChannelContext->getShippingLocation()->getAddress()->getStreet()
            );
            $request = $this->addressBuilder->buildDeliveryAddress(
                $shippingStreetAddress['street'],
                $shippingStreetAddress['houseNumber'],
                $salesChannelContext->getShippingLocation()->getAddress()->getZipcode(),
                $salesChannelContext->getShippingLocation()->getAddress()->getCity(),
                $shippingState,
                $salesChannelContext->getShippingLocation()->getAddress()->getCountry()->getIso(),
                $request
            );
        }

        //Setting billing address info if not present in statedata
        if (empty($request['billingAddress'])) {
            if ($salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountryState()) {
                $billingState = $salesChannelContext->getCustomer()
                    ->getActiveBillingAddress()->getCountryState()->getShortCode();
            } else {
                $billingState = '';
            }

            $billingStreetAddress = $this->splitStreetAddressHouseNumber(
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet()
            );
            $request = $this->addressBuilder->buildBillingAddress(
                $billingStreetAddress['street'],
                $billingStreetAddress['houseNumber'],
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getZipcode(),
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCity(),
                $billingState,
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountry()->getIso(),
                $request
            );
        }

        //Setting customer data if not present in statedata
        if (empty($request['shopperName'])) {
            $shopperFirstName = $salesChannelContext->getCustomer()->getFirstName();
            $shopperLastName = $salesChannelContext->getCustomer()->getLastName();
        } else {
            $shopperFirstName = $request['shopperName']['firstName'];
            $shopperLastName = $request['shopperName']['lastName'];
        }

        if (empty($request['shopperEmail'])) {
            $shopperEmail = $salesChannelContext->getCustomer()->getEmail();
        } else {
            $shopperEmail = $request['shopperEmail'];
        }

        if (empty($request['paymentMethod']['personalDetails']['telephoneNumber'])) {
            $shopperPhone = $salesChannelContext->getShippingLocation()->getAddress()->getPhoneNumber();
        } else {
            $shopperPhone = $request['paymentMethod']['personalDetails']['telephoneNumber'];
        }

        if (empty($request['paymentMethod']['personalDetails']['dateOfBirth'])) {
            if ($salesChannelContext->getCustomer()->getBirthday()) {
                $shopperDob = $salesChannelContext->getCustomer()->getBirthday()->format('dd-mm-yyyy');
            } else {
                $shopperDob = '';
            }
        } else {
            $shopperDob = $request['paymentMethod']['personalDetails']['dateOfBirth'];
        }

        if (empty($request['shopperLocale'])) {
            $shopperLocale = $this->salesChannelRepository->getSalesChannelAssocLocale($salesChannelContext)
                ->getLanguage()->getLocale()->getCode();
        } else {
            $shopperLocale = $request['shopperLocale'];
        }

        if (empty($request['shopperIP'])) {
            $shopperIp = $salesChannelContext->getCustomer()->getRemoteAddress();
        } else {
            $shopperIp = $request['shopperIP'];
        }

        if (empty($request['shopperReference'])) {
            $shopperReference = $salesChannelContext->getCustomer()->getId();
        } else {
            $shopperReference = $request['shopperReference'];
        }

        if (empty($request['countryCode'])) {
            $countryCode = $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountry()->getIso();
        } else {
            $countryCode = $request['countryCode'];
        }

        //Redirect parameters for 3DS1 payments
        $request['redirectFromIssuerMethod'] = 'GET';
        $request['redirectToIssuerMethod'] = 'POST';
        $request['returnUrl'] = $returnUrl;

        $request = $this->browserBuilder->buildBrowserData(
            $userAgent,
            $acceptHeader,
            isset($request['browserInfo']['screenWidth']) ? $request['browserInfo']['screenWidth'] : null,
            isset($request['browserInfo']['screenHeight']) ? $request['browserInfo']['screenHeight'] : null,
            isset($request['browserInfo']['colorDepth']) ? $request['browserInfo']['colorDepth'] : null,
            isset($request['browserInfo']['timeZoneOffset']) ? $request['browserInfo']['timeZoneOffset'] : null,
            isset($request['browserInfo']['language']) ? $request['browserInfo']['language'] : null,
            isset($request['browserInfo']['javaEnabled']) ? $request['browserInfo']['javaEnabled'] : null,
            $request
        );

        $request = $this->customerBuilder->buildCustomerData(
            false,
            $shopperEmail,
            $shopperPhone,
            '',
            $shopperDob,
            $shopperFirstName,
            $shopperLastName,
            $countryCode,
            $shopperLocale,
            $shopperIp,
            $shopperReference,
            $request
        );

        //Building payment data
        $request = $this->paymentBuilder->buildPaymentData(
            $salesChannelContext->getCurrency()->getIsoCode(),
            $this->currency->sanitize(
                $transaction->getOrder()->getPrice()->getTotalPrice(),
                $salesChannelContext->getCurrency()->getIsoCode()
            ),
            $transaction->getOrder()->getOrderNumber(),
            $this->configurationService->getMerchantAccount($salesChannelContext->getSalesChannel()->getId()),
            $returnUrl,
            $request
        );

        $request = $this->paymentBuilder->buildAlternativePaymentMethodData(
            $paymentMethodType,
            '',
            $request
        );

        if (static::$isOpenInvoice) {
            $orderLines = $transaction->getOrder()->getLineItems();
            $lineItems = [];
            foreach ($orderLines->getElements() as $orderLine) {
                //Getting line price
                $price = $orderLine->getPrice();

                //Getting order line information differently if it's a promotion or product
                if (empty($orderLine->getProductId()) && $orderLine->getType() === self::PROMOTION) {
                    $productName = $orderLine->getDescription();
                    $productNumber = $orderLine->getPayload()['promotionId'];
                } else {
                    $product = $this->getProduct($orderLine->getProductId(), $salesChannelContext->getContext());
                    $productName = $product->getName();
                    $productNumber = $product->getProductNumber();
                }

                //Getting line tax amount and rate
                $lineTax = $price->getCalculatedTaxes()->getAmount() / $orderLine->getQuantity();
                $taxRate = $price->getCalculatedTaxes()->first();
                if (!empty($taxRate)) {
                    $taxRate = $taxRate->getTaxRate();
                } else {
                    $taxRate = 0;
                }

                //Building open invoice line
                $lineItems[] = $this->openInvoiceBuilder->buildOpenInvoiceLineItem(
                    $productName,
                    $this->currency->sanitize(
                        $price->getUnitPrice() - $lineTax,
                        $this->getCurrency(
                            $transaction->getOrder()->getCurrencyId(),
                            $salesChannelContext->getContext()
                        )
                    ),
                    $this->currency->sanitize(
                        $lineTax,
                        $this->getCurrency(
                            $transaction->getOrder()->getCurrencyId(),
                            $salesChannelContext->getContext()
                        )
                    ),
                    $taxRate * 100,
                    $orderLine->getQuantity(),
                    '',
                    $productNumber
                );
            }

            $request['lineItems'] = $lineItems;
        }

        //Setting info from statedata additionalData if present
        if (!empty($stateDataAdditionalData['origin'])) {
            $request['origin'] = $stateDataAdditionalData['origin'];
        } else {
            $request['origin'] = $this->salesChannelRepository->getSalesChannelUrl($salesChannelContext);
        }

        $request['additionalData']['allow3DS2'] = true;

        $request['channel'] = 'web';

        //Remove the used state.data
        if ($stateData) {
            $this->paymentStateDataService->deletePaymentStateData($stateData);
        }

        return $request;
    }

    /**
     * Creates the Adyen Redirect Result URL with the same query as the original return URL
     * Fixes the CSRF validation bug: https://issues.shopware.com/issues/NEXT-6356
     *
     * @param $returnUrl
     * @return string
     * @throws PaymentException
     * @deprecated using redirectToIssuerMethod and redirectFromIssuerMethod in the
     * /payments call is not necessary to modify the CSRF token
     *
     */
    protected function getAdyenReturnUrl($returnUrl)
    {
        // Parse the original return URL to retrieve the query parameters
        $returnUrlQuery = parse_url($returnUrl, PHP_URL_QUERY);

        // In case the URL is malformed it cannot be parsed
        if (false === $returnUrlQuery) {
            throw new PaymentException('Return URL is malformed');
        }

        // Generate the custom Adyen endpoint to receive the redirect from the issuer page
        $adyenReturnUrl = $this->router->generate(
            'adyen_redirect_result',
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

    /**
     * @param string $currencyId
     * @param Context $context
     * @return CurrencyEntity
     */
    private function getCurrency(string $currencyId, Context $context): CurrencyEntity
    {
        $criteria = new Criteria([$currencyId]);

        /** @var CurrencyCollection $currencyCollection */
        $currencyCollection = $this->currencyRepository->search($criteria, $context);

        $currency = $currencyCollection->get($currencyId);
        if ($currency === null) {
            throw new CurrencyNotFoundException($currencyId);
        }

        return $currency;
    }

    /**
     * @param string $productId
     * @param Context $context
     * @return ProductEntity
     */
    private function getProduct(string $productId, Context $context): ProductEntity
    {
        $criteria = new Criteria([$productId]);

        /** @var ProductCollection $productCollection */
        $productCollection = $this->productRepository->search($criteria, $context);

        $product = $productCollection->get($productId);
        if ($product === null) {
            throw new ProductNotFoundException($productId);
        }

        return $product;
    }
}
