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
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Adyen\Shopware\Exception\CurrencyNotFoundException;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

abstract class AbstractPaymentMethodHandler implements AsynchronousPaymentHandlerInterface
{
    const SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';
    const SHOPPER_INTERACTION_ECOMMERCE = 'Ecommerce';

    const ALLOWED_LINE_ITEM_TYPES = [
        'product',
        'option-values',
        'customized-products-option'
    ];
    const PROMOTION = 'promotion';
    /**
     * Error codes that are safe to display to the shopper.
     * @see https://docs.adyen.com/development-resources/error-codes
     */
    const SAFE_ERROR_CODES = ['124'];

    public static $isOpenInvoice = false;
    public static $isGiftCard = false;
    public static $supportsManualCapture = false;
    public static $supportsPartialCapture = false;

    /**
     * @var ClientService
     */
    protected $clientService;

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
    protected $symfonyRouter;

    /**
     * @var EntityRepository
     */
    protected $currencyRepository;

    /**
     * @var EntityRepository
     */
    protected $productRepository;

    /**
     * @var SessionInterface
     */
    protected $session;

    /**
     * @var AbstractContextSwitchRoute
     */
    private $contextSwitchRoute;

    private $checkoutService;

    private $paymentResults = [];

    private $orderRequestData = [];

    private $remainingAmount = null;

    /**
     * AbstractPaymentMethodHandler constructor.
     *
     * @param ConfigurationService $configurationService
     * @param ClientService $clientService
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
     * @param RouterInterface $symfonyRouter
     * @param SessionInterface $session
     * @param EntityRepository $currencyRepository
     * @param EntityRepository $productRepository
     * @param LoggerInterface $logger
     * @param AbstractContextSwitchRoute $contextSwitchRoute
     */
    public function __construct(
        ConfigurationService $configurationService,
        ClientService $clientService,
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
        RouterInterface $symfonyRouter,
        SessionInterface $session,
        Session $session,
        EntityRepository $currencyRepository,
        EntityRepository $productRepository,
        AbstractContextSwitchRoute $contextSwitchRoute,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
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
        $this->symfonyRouter = $symfonyRouter;
        $this->session = $session;
        $this->currencyRepository = $currencyRepository;
        $this->productRepository = $productRepository;
        $this->contextSwitchRoute = $contextSwitchRoute;
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
        $this->checkoutService = new CheckoutService(
            $this->clientService->getClient($salesChannelContext->getSalesChannel()->getId())
        );
        $requestStateData = $dataBag->get('stateData');
        if ($requestStateData) {
            $requestStateData = json_decode($requestStateData, true);
        }

        $this->handleAdyenOrderPayment($transaction, $dataBag, $salesChannelContext);

        $transactionId = $transaction->getOrderTransaction()->getId();
        $storedStateData = $this->getStoredStateData($salesChannelContext, $transactionId);
        $stateData = $requestStateData ?? $storedStateData ?? [];

        try {
            $request = $this->preparePaymentsRequest(
                $salesChannelContext,
                $transaction,
                $stateData,
                $this->remainingAmount,
                $this->orderRequestData
            );
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

        if ($storedStateData) {
            // Remove the used state.data
            $this->paymentStateDataService->deletePaymentStateDataFromContextToken($salesChannelContext->getToken());
        }

        try {
            $response = $this->checkoutService->payments($request);
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request. Order number %s: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->displaySafeErrorMessages($exception);
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if (empty($orderNumber)) {
            $message = 'Order number is missing';
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $this->paymentResults[] = $this->paymentResponseHandler
            ->handlePaymentResponse($response, $transaction->getOrderTransaction(), false);

        try {
            $this->paymentResponseHandler
                ->handleShopwareApis($transaction, $salesChannelContext, $this->paymentResults);
        } catch (PaymentCancelledException $exception) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw new AsyncPaymentFinalizeException($transactionId, $exception->getMessage());
        }

        /*
         * Removes the giftcard payment method from the databag
         *  if the previous order was completed with a giftcard.
         */
        $this->contextSwitchRoute->switchContext(
            new RequestDataBag(
                [
                    SalesChannelContextService::PAYMENT_METHOD_ID => null
                ]
            ),
            $salesChannelContext
        );

        // Payment had no error, continue the process
        return new RedirectResponse($transaction->getReturnUrl());
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
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param array $request
     * @param int|null $partialAmount
     * @param array|null $adyenOrderData
     * @return array
     */
    protected function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        array $request = [],
        ?int $partialAmount = null,
        ?array $adyenOrderData = []
    ): array {
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

        if (static::$isGiftCard) {
            $request['paymentMethod']['brand'] = static::getBrand();
        }

        if (!empty($request['storePaymentMethod']) && $request['storePaymentMethod'] === true) {
            $request['recurringProcessingModel'] = 'CardOnFile';
        }

        if (static::class === OneClickPaymentMethodHandler::class) {
            $request['shopperInteraction'] = self::SHOPPER_INTERACTION_CONTAUTH;
        } else {
            $request['shopperInteraction'] = self::SHOPPER_INTERACTION_ECOMMERCE;
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
                $shippingState = 'n/a';
            }

            $shippingStreetAddress = $this->getSplitStreetAddressHouseNumber(
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
                $billingState = 'n/a';
            }

            $billingStreetAddress = $this->getSplitStreetAddressHouseNumber(
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
                $shopperDob = $salesChannelContext->getCustomer()->getBirthday()->format('Y-m-d');
            } else {
                $shopperDob = '';
            }
        } else {
            $shopperDob = $request['paymentMethod']['personalDetails']['dateOfBirth'];
        }

        if (empty($request['shopperLocale'])) {
            $shopperLocale = $this->salesChannelRepository
                ->getSalesChannelAssoc($salesChannelContext, ['language.locale'])
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
        $amount = $partialAmount ?: $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );
        $request = $this->paymentBuilder->buildPaymentData(
            $salesChannelContext->getCurrency()->getIsoCode(),
            $amount,
            $transaction->getOrder()->getOrderNumber(),
            $this->configurationService->getMerchantAccount($salesChannelContext->getSalesChannel()->getId()),
            $transaction->getReturnUrl(),
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
                if (!in_array($orderLine->getType(), self::ALLOWED_LINE_ITEM_TYPES)) {
                    continue;
                }

                $productNumber = $orderLine->getReferencedId();
                $productName = $orderLine->getLabel();

                $price = $orderLine->getPrice();
                $lineTax = $price->getCalculatedTaxes()->getAmount() / $orderLine->getQuantity();
                $taxRate = $price->getCalculatedTaxes()->first();
                if (!empty($taxRate)) {
                    $taxRate = $taxRate->getTaxRate();
                } else {
                    $taxRate = 0;
                }
                $currency = $this->getCurrency(
                    $transaction->getOrder()->getCurrencyId(),
                    $salesChannelContext->getContext()
                );

                $product =
                    !is_null($orderLine->getProductId()) ?
                    $this->getProduct($orderLine->getProductId(), $salesChannelContext->getContext()) :
                    null;

                // Add url for only real product and not for the custom cart items.
                if (!is_null($product->getId())) {
                    $productUrl = sprintf(
                        "%s/detail/%s",
                        $salesChannelContext->getSalesChannel()->getDomains()->first()->getUrl(),
                        $product->getId()
                    );
                } else {
                    $productUrl = null;
                }

                if (isset($product) && !is_null($product->getCover())) {
                    $imageUrl = $product->getCover()->getMedia()->getUrl();
                } else {
                    $imageUrl = null;
                }

                if (isset($product) && !is_null($product->getCategories())) {
                    $productCategory = $product->getCategories()->first()->getName();
                } else {
                    $productCategory = null;
                }

                //Building open invoice line
                $lineItems[] = $this->openInvoiceBuilder->buildOpenInvoiceLineItem(
                    $productName,
                    $this->currency->sanitize(
                        $price->getUnitPrice() -
                        ($transaction->getOrder()->getTaxStatus() == 'gross' ? $lineTax : 0),
                        $currency
                    ),
                    $this->currency->sanitize(
                        $lineTax,
                        $currency
                    ),
                    $taxRate * 100,
                    $orderLine->getQuantity(),
                    '',
                    $productNumber,
                    $productUrl,
                    $imageUrl,
                    $this->currency->sanitize(
                        $price->getUnitPrice(),
                        $currency
                    ),
                    $productCategory
                );
            }

            $request['lineItems'] = $lineItems;
        }

        //Setting info from statedata additionalData if present
        if (!empty($stateDataAdditionalData['origin'])) {
            $request['origin'] = $stateDataAdditionalData['origin'];
        } else {
            $origin = $this->salesChannelRepository->getCurrentDomainUrl($salesChannelContext);
            $request['origin'] = $origin;
        }

        $request['additionalData']['allow3DS2'] = true;

        $request['channel'] = 'web';

        if (!empty($adyenOrderData)) {
            $request['order'] = $adyenOrderData;
        }

        return $request;
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

        $criteria->addAssociation('cover');
        $criteria->addAssociation('categories');

        /** @var ProductCollection $productCollection */
        $productCollection = $this->productRepository->search($criteria, $context);

        $product = $productCollection->get($productId);
        if ($product === null) {
            throw new ProductNotFoundException($productId);
        }

        return $product;
    }

    private function displaySafeErrorMessages(AdyenException $exception)
    {
        if ('validation' === $exception->getErrorType()
            && in_array($exception->getAdyenErrorCode(), self::SAFE_ERROR_CODES)) {
            $this->session->getFlashBag()->add('warning', $exception->getMessage());
        }
    }

    /**
     * @param string $address
     * @return array
     */
    private function getSplitStreetAddressHouseNumber(string $address): array
    {
        $streetFirstRegex = '/(?<streetName>[\w\W]+)\s+(?<houseNumber>[\d-]{1,10}((\s)?\w{1,3})?)$/m';
        $numberFirstRegex = '/^(?<houseNumber>[\d-]{1,10}((\s)?\w{1,3})?)\s+(?<streetName>[\w\W]+)/m';

        preg_match($streetFirstRegex, $address, $streetFirstAddress);
        preg_match($numberFirstRegex, $address, $numberFirstAddress);

        if ($streetFirstAddress) {
            return [
                'street' => $streetFirstAddress['streetName'],
                'houseNumber' => $streetFirstAddress['houseNumber']
            ];
        } elseif ($numberFirstAddress) {
            return [
                'street' => $numberFirstAddress['streetName'],
                'houseNumber' => $numberFirstAddress['houseNumber']
            ];
        }

        return [
            'street' => $address,
            'houseNumber' => 'N/A'
        ];
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    public function handleAdyenOrderPayment(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $adyenOrder = $dataBag->get('order');
        if (!$adyenOrder) {
            return;
        }
        // order has been created, use state data from db as the first payment
        $transactionId = $transaction->getOrderTransaction()->getId();
        $storedStateData = $this->getStoredStateData($salesChannelContext, $transactionId);
        if (!$storedStateData) {
            $message = sprintf(
                "There was an error with the giftcard payment. Order number: %s; Missing: giftcard data",
                $transaction->getOrder()->getOrderNumber()
            );
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $this->orderRequestData = [
            'orderData' => $adyenOrder->get('orderData'),
            'pspReference' => $adyenOrder->get('pspReference')
        ];
        $partialAmount = (int) $storedStateData['additionalData']['amount'];
        try {
            $giftcardPaymentRequest = $this->preparePaymentsRequest(
                $salesChannelContext,
                $transaction,
                $storedStateData,
                $partialAmount,
                $this->orderRequestData
            );
        } catch (AsyncPaymentProcessException $exception) {
            $this->logger->error($exception->getMessage());
            throw $exception;
        } catch (\Exception $exception) {
            $message = sprintf(
                "There was an error with the giftcard payment. Order number: %s Missing data: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        try {
            $giftcardPaymentResponse = $this->checkoutService->payments($giftcardPaymentRequest);
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the giftcard payment request. Order number %s: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->displaySafeErrorMessages($exception);
            $this->logger->error($message);
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

        $this->paymentResults[] = $this->paymentResponseHandler
            ->handlePaymentResponse($giftcardPaymentResponse, $transaction->getOrderTransaction(), false);

        $this->remainingAmount = $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        ) - $partialAmount;

        // Remove the used state.data
        $this->paymentStateDataService->deletePaymentStateDataFromContextToken($salesChannelContext->getToken());
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param string $transactionId
     * @return array|null
     */
    public function getStoredStateData(SalesChannelContext $salesChannelContext, string $transactionId): ?array
    {
        // Check for state.data in db using the context token
        $storedStateData = null;
        $stateDataEntity = $this->paymentStateDataService->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        );
        if ($stateDataEntity) {
            $storedStateData = json_decode($stateDataEntity->getStateData(), true);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AsyncPaymentProcessException(
                $transactionId,
                'Invalid payment state data.'
            );
        }
        return $storedStateData;
    }
}
