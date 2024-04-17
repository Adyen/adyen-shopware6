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
use Adyen\Model\Checkout\CheckoutPaymentMethod;
use Adyen\Model\Checkout\EncryptedOrderData;
use Adyen\Model\Checkout\LineItem;
use Adyen\Model\Checkout\PaymentRequest;
use Adyen\Model\Checkout\Address;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\BrowserInfo;
use Adyen\Model\Checkout\Name;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\OrdersService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Util\Currency;
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
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

    public static bool $isOpenInvoice = false;
    public static bool $supportsManualCapture = false;
    public static bool $supportsPartialCapture = false;

    /**
     * @var ClientService
     */
    protected $clientService;

    /**
     * @var Currency
     */
    protected $currency;

    /**
     * @var ConfigurationService
     */
    protected $configurationService;

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
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var AbstractContextSwitchRoute
     */
    private $contextSwitchRoute;

    private $paymentsApiService;

    private $paymentResults = [];

    private $orderRequestData = [];

    private $remainingAmount = null;

    private OrdersService $ordersService;

    /**
     * AbstractPaymentMethodHandler constructor.
     *
     * @param OrdersService $ordersService
     * @param ClientService $clientService
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStateDataService $paymentStateDataService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param ResultHandler $resultHandler
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param RouterInterface $symfonyRouter
     * @param RequestStack $requestStack
     * @param EntityRepository $currencyRepository
     * @param EntityRepository $productRepository
     * @param AbstractContextSwitchRoute $contextSwitchRoute
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrdersService $ordersService,
        ConfigurationService $configurationService,
        ClientService $clientService,
        Currency $currency,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStateDataService $paymentStateDataService,
        SalesChannelRepository $salesChannelRepository,
        PaymentResponseHandler $paymentResponseHandler,
        ResultHandler $resultHandler,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        RouterInterface $symfonyRouter,
        RequestStack $requestStack,
        EntityRepository $currencyRepository,
        EntityRepository $productRepository,
        AbstractContextSwitchRoute $contextSwitchRoute,
        LoggerInterface $logger
    ) {
        $this->ordersService = $ordersService;
        $this->clientService = $clientService;
        $this->currency = $currency;
        $this->configurationService = $configurationService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->resultHandler = $resultHandler;
        $this->logger = $logger;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->symfonyRouter = $symfonyRouter;
        $this->requestStack = $requestStack;
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

        $this->paymentsApiService = new PaymentsApi(
            $this->clientService->getClient($salesChannelContext->getSalesChannel()->getId())
        );

        $countStateData= 0;
        $requestStateData = $dataBag->get('stateData');
        if ($requestStateData) {
            $requestStateData = json_decode($requestStateData, true);
            $countStateData++;
        }
        $countStoredStateData = $this->paymentStateDataService->countStoredStateData($salesChannelContext);
        $countStateData += $countStoredStateData;
        //If condition to check more than 1 PM
        if ($countStateData > 1) {
            $adyenOrderResponse = $this->createAdyenOrder($salesChannelContext, $transaction);
            $this->handleAdyenOrderPayment($transaction, $adyenOrderResponse, $salesChannelContext);
        }

        $transactionId = $transaction->getOrderTransaction()->getId();
        $storedStateData = $this->paymentStateDataService->getStoredStateData($salesChannelContext, $transactionId);
        $stateData = $requestStateData ?? $storedStateData ?? [];

        if (isset($stateData)) {
            $request = $this->getPaymentRequest(
                $salesChannelContext,
                $transaction,
                $stateData,
                $this->remainingAmount,
                $this->orderRequestData
            );
            //make /payments call
            $this->paymentsCall($salesChannelContext, $request, $transaction);
            //Remove all state data if stored or from giftcard
            if ($storedStateData) {
                $this->paymentStateDataService->deletePaymentStateDataFromId($storedStateData['id']);
            }
        }

        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if (empty($orderNumber)) {
            $message = 'Order number is missing';
            throw new AsyncPaymentProcessException($transactionId, $message);
        }

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

    private function createAdyenOrder(SalesChannelContext $salesChannelContext, $transaction)
    {
        $uuid = Uuid::randomHex();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );
        return $this->ordersService->createOrder($salesChannelContext, $uuid, $amount, $currency);
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
     * @return PaymentRequest
     */
    protected function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        array $request = [],
        ?int $partialAmount = null,
        ?array $adyenOrderData = []
    ): PaymentRequest {

        $paymentRequest = new PaymentRequest($request);

        if (!empty($request['additionalData'])) {
            $stateDataAdditionalData = $request['additionalData'];
        }

        //Validate state.data for payment and build request object
        $request = $this->checkoutStateDataValidator->getValidatedAdditionalData($request);

        //set payment method
        if (!empty($request)) {
            $paymentMethod = new CheckoutPaymentMethod($request['paymentMethod']);
        } else {
            $paymentMethod = new CheckoutPaymentMethod();
        }

        //Setting payment method type if not present in statedata
        if (empty($request['paymentMethod']['type'])) {
            $paymentMethodType = static::getPaymentMethodCode();
        } else {
            $paymentMethodType = $request['paymentMethod']['type'];
        }
      
        $paymentMethod->setType($paymentMethodType ?? 'zip');
        $paymentRequest->setPaymentMethod($paymentMethod);

        if (!empty($request['storePaymentMethod']) && $request['storePaymentMethod'] === true) {
            $paymentRequest->setStorePaymentMethod($request['storePaymentMethod']);
            $paymentRequest->setRecurringProcessingModel('CardOnFile');
        }

        if (static::class === OneClickPaymentMethodHandler::class) {
            $paymentRequest->setShopperInteraction(self::SHOPPER_INTERACTION_CONTAUTH);
            $paymentRequest->setRecurringProcessingModel('CardOnFile');
        } else {
            $paymentRequest->setShopperInteraction(self::SHOPPER_INTERACTION_ECOMMERCE);
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

        if (!empty($request['browserInfo'])) {
            $browserInfo = new BrowserInfo();
            $browserInfo->setUserAgent($userAgent);
            $browserInfo->setAcceptHeader($acceptHeader);
            $browserInfo->setScreenWidth($request['browserInfo']['screenWidth']);
            $browserInfo->setScreenHeight($request['browserInfo']['screenHeight']);
            $browserInfo->setColorDepth($request['browserInfo']['colorDepth']);
            $browserInfo->setTimeZoneOffset($request['browserInfo']['timeZoneOffset']);
            $browserInfo->setLanguage($request['browserInfo']['language']);
            $browserInfo->setJavaEnabled($request['browserInfo']['javaEnabled']);

            $paymentRequest->setBrowserInfo($browserInfo);
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

            $addressInfo = new Address();
            $addressInfo->setStreet($shippingStreetAddress['street']);
            $addressInfo->setHouseNumberOrName($shippingStreetAddress['houseNumber']);
            $addressInfo->setPostalCode($salesChannelContext->getShippingLocation()->getAddress()->getZipcode());
            $addressInfo->setCity($salesChannelContext->getShippingLocation()->getAddress()->getCity());
            $addressInfo->setStateOrProvince($shippingState);
            $addressInfo->setCountry($salesChannelContext->getShippingLocation()->getAddress()->getCountry()->getIso());

            $paymentRequest->setDeliveryAddress($addressInfo);
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

            $addressInfo = new Address();
            $addressInfo->setStreet($billingStreetAddress['street']);
            $addressInfo->setHouseNumberOrName($billingStreetAddress['houseNumber']);
            $addressInfo->setPostalCode($salesChannelContext->getCustomer()->getActiveBillingAddress()->getZipcode());
            $addressInfo->setCity($salesChannelContext->getCustomer()->getActiveBillingAddress()->getCity());
            $addressInfo->setStateOrProvince($billingState);
            $addressInfo->setCountry(
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountry()->getIso()
            );
            $paymentRequest->setBillingAddress($addressInfo);
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

        if (empty($request['telephoneNumber'])) {
            $shopperPhone = $salesChannelContext->getShippingLocation()->getAddress()->getPhoneNumber();
        } else {
            $shopperPhone = $request['telephoneNumber'];
        }

        if (empty($request['dateOfBirth'])) {
            if ($salesChannelContext->getCustomer()->getBirthday()) {
                $shopperDob = $salesChannelContext->getCustomer()->getBirthday()->format('Y-m-d');
            } else {
                $shopperDob = '';
            }
        } else {
            $shopperDob = $request['dateOfBirth'];
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

        $shopperName = new Name();
        $shopperName->setFirstName($shopperFirstName);
        $shopperName->setLastName($shopperLastName);

        $paymentRequest->setShopperName($shopperName);
        $paymentRequest->setShopperEmail($shopperEmail);
        if (!empty($shopperPhone)) {
            $paymentRequest->setTelephoneNumber($shopperPhone);
        }
        $paymentRequest->setDateOfBirth($shopperDob);
        $paymentRequest->setCountryCode($countryCode);
        $paymentRequest->setShopperLocale($shopperLocale);
        $paymentRequest->setShopperIP($shopperIp);
        $paymentRequest->setShopperReference($shopperReference);

        //Building payment data
        $amount = $partialAmount ?: $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $amountInfo = new Amount();
        $amountInfo->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $amountInfo->setValue($amount);

        $paymentRequest->setAmount($amountInfo);
        $paymentRequest->setReference($transaction->getOrder()->getOrderNumber());
        $paymentRequest->setMerchantAccount(
            $this->configurationService->getMerchantAccount($salesChannelContext->getSalesChannel()->getId())
        );
        $paymentRequest->setReturnUrl($transaction->getReturnUrl());

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

                if (isset($product) && !is_null($product->getCategories()) && $product->getCategories()->count() > 0) {
                    $productCategory = $product->getCategories()->first()->getName();
                } else {
                    $productCategory = null;
                }

                $currency = $salesChannelContext->getCurrency();

                //Building open invoice line
              
                $lineItem = new LineItem();

                $lineItem->setDescription($productName);
                $lineItem->setAmountExcludingTax($this->currency->sanitize(
                    $price->getUnitPrice() -
                    ($transaction->getOrder()->getTaxStatus() == 'gross' ? $lineTax : 0),
                    $currency->getIsoCode()
                ));
                $lineItem->setTaxAmount($this->currency->sanitize(
                    $lineTax,
                    $currency->getIsoCode()
                ));
                $lineItem->setTaxPercentage($taxRate * 100);
                $lineItem->setQuantity($orderLine->getQuantity());
                $lineItem->setId($productNumber);
                $lineItem->setProductUrl($productUrl);
                $lineItem->setImageUrl($imageUrl);
                $lineItem->setAmountIncludingTax($this->currency->sanitize(
                    $price->getUnitPrice(),
                    $currency->getIsoCode()
                ));
                $lineItem->setItemCategory($productCategory);

                $lineItems[] = $lineItem;
            }

            $paymentRequest->setLineItems($lineItems);
        }

        //Setting info from statedata additionalData if present
        if (!empty($stateDataAdditionalData['origin'])) {
            $origin = $stateDataAdditionalData['origin'];
        } else {
            $origin = $this->salesChannelRepository->getCurrentDomainUrl($salesChannelContext);
        }

        $paymentRequest->setOrigin($origin);
        $paymentRequest->setAdditionaldata(['allow3DS2' => true]);

        $paymentRequest->setChannel('Web');
        if (!empty($adyenOrderData)) {
            $encryptedOrderData = new EncryptedOrderData();
            $encryptedOrderData->setOrderData($adyenOrderData['orderData']);
            $encryptedOrderData->setPspReference($adyenOrderData['pspReference']);
            $paymentRequest->setOrder($encryptedOrderData);
        }

        return $paymentRequest;
    }

    private function getPaymentRequest(
        $salesChannelContext,
        $transaction,
        $stateData,
        $partialAmount,
        $orderRequestData
    ) {
        $transactionId = $transaction->getOrderTransaction()->getId();
        try {
            $request = $this->preparePaymentsRequest(
                $salesChannelContext,
                $transaction,
                $stateData,
                $partialAmount,
                $orderRequestData
            );
            return $request;
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
    }

    private function paymentsCall($salesChannelContext, $request, $transaction)
    {
        $transactionId = $transaction->getOrderTransaction()->getId();
        try {
            $this->clientService->logRequest(
                $request,
                Client::API_CHECKOUT_VERSION,
                '/payments',
                $salesChannelContext->getSalesChannelId()
            );

            $response = $this->paymentsApiService->payments($request);

            $this->clientService->logResponse(
                $response,
                $salesChannelContext->getSalesChannelId()
            );
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
        $this->paymentResults[] = $this->paymentResponseHandler
            ->handlePaymentResponse($response, $transaction->getOrderTransaction(), false);
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
            $this->requestStack->getSession()->getFlashBag()->add('warning', $exception->getMessage());
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
        $adyenOrderResponse,
        SalesChannelContext $salesChannelContext
    ): void {
        if (empty($adyenOrderResponse)) {
            return;
        }
        $transactionId = $transaction->getOrderTransaction()->getId();

        //New Multi-Gift-card implementation
        $remainingOrderAmount = $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $this->orderRequestData = [
            'orderData' => $adyenOrderResponse['orderData'],
            'pspReference' => $adyenOrderResponse['pspReference']
        ];

        $stateData = $this->paymentStateDataService->fetchRedeemedGiftCardsFromContextToken(
            $salesChannelContext->getToken()
        );

        foreach ($stateData->getElements() as $statedataArray) {
            $storedStateData = json_decode($statedataArray->getStateData(), true);
            $giftcardValue = $this->currency->sanitize(
                $storedStateData['giftcard']['value'],
                $salesChannelContext->getCurrency()->getIsoCode()
            );
            $partialAmount = min($remainingOrderAmount, $giftcardValue); //convert to integer from float

            $giftcardPaymentRequest = $this->getPaymentRequest(
                $salesChannelContext,
                $transaction,
                $storedStateData,
                $partialAmount,
                $this->orderRequestData
            );

            //make /payments call
            $this->paymentsCall($salesChannelContext, $giftcardPaymentRequest, $transaction);

            $remainingOrderAmount -= $partialAmount;

            // Remove the used state.data
            $this->paymentStateDataService->deletePaymentStateDataFromId($statedataArray->getId());
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AsyncPaymentProcessException(
                $transactionId,
                'Invalid payment state data.'
            );
        }

        $this->remainingAmount = $remainingOrderAmount;
    }
}
