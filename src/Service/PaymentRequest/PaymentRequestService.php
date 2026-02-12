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

namespace Adyen\Shopware\Service\PaymentRequest;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Model\Checkout\Address;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\BrowserInfo;
use Adyen\Model\Checkout\CheckoutPaymentMethod;
use Adyen\Model\Checkout\Company;
use Adyen\Model\Checkout\EncryptedOrderData;
use Adyen\Model\Checkout\LineItem;
use Adyen\Model\Checkout\Name;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Adyen\Shopware\PaymentMethods\RatepayDirectdebitPaymentMethod;
use Adyen\Shopware\PaymentMethods\RatepayPaymentMethod;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Util\CheckoutStateDataValidator;
use Adyen\Shopware\Util\Currency;
use Adyen\Shopware\Util\RatePayDeviceFingerprintParamsProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class PaymentRequestService.
 *
 * @package Adyen\Shopware\Service\PaymentRequest
 */
class PaymentRequestService
{
    /**
     * Error codes that are safe to display to the shopper.
     *
     * @see https://docs.adyen.com/development-resources/error-codes
     */
    const SAFE_ERROR_CODES = ['124'];

    /** @var string */
    const SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';

    /** @var string */
    const SHOPPER_INTERACTION_ECOMMERCE = 'Ecommerce';

    /** @var string[] */
    const ALLOWED_LINE_ITEM_TYPES = [
        'product',
        'option-values',
        'customized-products-option'
    ];

    /**
     * @var ClientService $_clientService
     */
    private ClientService $clientService;

    /**
     * @var ConfigurationService $configurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var Currency $currency
     */
    private Currency $currency;

    /**
     * @var CheckoutStateDataValidator $checkoutStateDataValidator
     */
    private CheckoutStateDataValidator $checkoutStateDataValidator;

    /**
     * @var RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider
     */
    private RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider;

    /**
     * @var SalesChannelRepository $salesChannelRepository
     */
    private SalesChannelRepository $salesChannelRepository;

    /**
     * @var EntityRepository $productRepository
     */
    private EntityRepository $productRepository;

    /**
     * @var RequestStack $requestStack
     */
    private RequestStack $requestStack;

    /**
     * @var LoggerInterface $logger
     */
    private LoggerInterface $logger;

    /**
     * @param ClientService $clientService
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider
     * @param SalesChannelRepository $salesChannelRepository
     * @param EntityRepository $productRepository
     * @param RequestStack $requestStack
     * @param LoggerInterface $logger
     */
    public function __construct(
        ClientService $clientService,
        ConfigurationService $configurationService,
        Currency $currency,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider,
        SalesChannelRepository $salesChannelRepository,
        EntityRepository $productRepository,
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->ratePayFingerprintParamsProvider = $ratePayFingerprintParamsProvider;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->productRepository = $productRepository;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    /**
     * Build payment request from order entity
     *
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $orderEntity
     * @param string $returnUrl
     * @param string $paymentMethodCode
     * @param array $stateData
     * @param int|null $partialAmount
     * @param array $adyenOrderData
     * @param bool $isOpenInvoice
     * @param string $shopperInteraction
     *
     * @return IntegrationPaymentRequest
     */
    public function buildPaymentRequestFromOrder(
        SalesChannelContext $salesChannelContext,
        OrderEntity $orderEntity,
        string $returnUrl,
        string $paymentMethodCode,
        array $stateData = [],
        ?int $partialAmount = null,
        array $adyenOrderData = [],
        bool $isOpenInvoice = false,
        string $shopperInteraction = self::SHOPPER_INTERACTION_ECOMMERCE
    ): IntegrationPaymentRequest {
        $paymentRequest = new IntegrationPaymentRequest($stateData);

        $additionalData = $stateData['additionalData'] ?? [];

        // Validate state.data for payment and build request object
        $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);

        // Set payment method
        $paymentMethod = new CheckoutPaymentMethod($stateData['paymentMethod'] ?? null);
        $paymentMethodType = $stateData['paymentMethod']['type'] ?? $paymentMethodCode;
        $paymentMethod->setType($paymentMethodType);
        $paymentRequest->setPaymentMethod($paymentMethod);

        // Handle stored payment method
        if (!empty($stateData['storePaymentMethod']) && $stateData['storePaymentMethod'] === true) {
            $paymentRequest->setStorePaymentMethod($stateData['storePaymentMethod']);
            $paymentRequest->setRecurringProcessingModel('CardOnFile');
        }

        // Set shopper interaction
        $paymentRequest->setShopperInteraction($shopperInteraction);
        if ($shopperInteraction === self::SHOPPER_INTERACTION_CONTAUTH) {
            $paymentRequest->setRecurringProcessingModel('CardOnFile');
        }

        // Set browser info
        $this->setBrowserInfo($paymentRequest, $stateData);

        // Set addresses
        $this->setDeliveryAddress($paymentRequest, $salesChannelContext, $stateData);
        $this->setBillingAddress($paymentRequest, $salesChannelContext, $stateData);

        // Set shopper data
        $this->setShopperData($paymentRequest, $salesChannelContext, $stateData);

        // Set company data (for Billie)
        if (!empty($stateData['billieData'])) {
            $this->setCompanyData($paymentRequest, $stateData['billieData']);
        }

        // Set amount and reference
        $amount = $partialAmount ?: $this->currency->sanitize(
            $orderEntity->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $amountInfo = new Amount();
        $amountInfo->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $amountInfo->setValue($amount);

        $paymentRequest->setAmount($amountInfo);
        $paymentRequest->setReference($orderEntity->getOrderNumber());
        $paymentRequest->setMerchantAccount(
            $this->configurationService->getMerchantAccount($salesChannelContext->getSalesChannel()->getId())
        );
        $paymentRequest->setReturnUrl($returnUrl);

        // Set device fingerprint for Ratepay
        if (in_array($paymentMethodType, [
            RatepayPaymentMethod::RATEPAY_PAYMENT_METHOD_TYPE,
            RatepayDirectdebitPaymentMethod::RATEPAY_DIRECTDEBIT_PAYMENT_METHOD_TYPE
        ])) {
            $paymentRequest->setDeviceFingerprint($this->ratePayFingerprintParamsProvider->getToken());
        }

        // Set line items for open invoice
        if ($isOpenInvoice) {
            $this->setLineItems($paymentRequest, $orderEntity, $salesChannelContext);
        }

        // Set origin and additional data
        $origin = $additionalData['origin'] ??
            $stateData['origin'] ??
            $this->salesChannelRepository->getCurrentDomainUrl($salesChannelContext);

        $paymentRequest->setOrigin($origin);
        $paymentRequest->setAdditionaldata(['allow3DS2' => true]);
        $paymentRequest->setChannel('Web');

        // Set order data for multi-payment scenarios
        if (!empty($adyenOrderData)) {
            $encryptedOrderData = new EncryptedOrderData();
            $encryptedOrderData->setOrderData($adyenOrderData['orderData']);
            $encryptedOrderData->setPspReference($adyenOrderData['pspReference']);
            $paymentRequest->setOrder($encryptedOrderData);
        }

        return $paymentRequest;
    }

    /**
     * Build payment request from cart (for express checkout)
     *
     * @param SalesChannelContext $salesChannelContext
     * @param Cart $cart
     * @param string $returnUrl
     * @param string $orderReference
     * @param string $paymentMethodCode
     * @param array $stateData
     * @param bool $includeShipping
     *
     * @return IntegrationPaymentRequest
     */
    public function buildPaymentRequestFromCart(
        SalesChannelContext $salesChannelContext,
        Cart $cart,
        string $returnUrl,
        string $orderReference,
        string $paymentMethodCode,
        array $stateData = [],
        bool $includeShipping = true
    ): IntegrationPaymentRequest {
        $paymentRequest = new IntegrationPaymentRequest($stateData);

        // Validate state.data
        $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);

        // Set payment method
        $paymentMethod = new CheckoutPaymentMethod($stateData['paymentMethod'] ?? null);
        $paymentMethod->setType($paymentMethodCode);
        $paymentRequest->setPaymentMethod($paymentMethod);

        // Calculate amount
        $price = $cart->getPrice()->getTotalPrice();
        if (!$includeShipping) {
            $price -= $cart->getShippingCosts()->getTotalPrice();
        }

        $orderAmount = $this->currency->sanitize(
            $price,
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $amount = new Amount();
        $amount->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $amount->setValue($orderAmount);

        $paymentRequest->setAmount($amount);
        $paymentRequest->setReference($orderReference);
        $paymentRequest->setMerchantAccount(
            $this->configurationService->getMerchantAccount($salesChannelContext->getSalesChannel()->getId())
        );
        $paymentRequest->setReturnUrl($returnUrl);

        // Set addresses and shopper data if customer exists
        if ($salesChannelContext->getCustomer()) {
            $this->setDeliveryAddress($paymentRequest, $salesChannelContext, $stateData);
            $this->setBillingAddress($paymentRequest, $salesChannelContext, $stateData);
            $this->setShopperData($paymentRequest, $salesChannelContext, $stateData);
        }

        $paymentRequest->setOrigin($this->salesChannelRepository->getCurrentDomainUrl($salesChannelContext));
        $paymentRequest->setAdditionaldata(['allow3DS2' => true]);
        $paymentRequest->setChannel('Web');
        $paymentRequest->setShopperInteraction(self::SHOPPER_INTERACTION_ECOMMERCE);

        return $paymentRequest;
    }

    /**
     * Execute payment API call
     *
     * @param SalesChannelContext $salesChannelContext
     * @param IntegrationPaymentRequest $request
     *
     * @return PaymentResponse
     *
     * @throws AdyenException
     */
    public function executePayment(
        SalesChannelContext $salesChannelContext,
        IntegrationPaymentRequest $request
    ): PaymentResponse {
        $paymentsApi = new PaymentsApi(
            $this->clientService->getClient($salesChannelContext->getSalesChannelId())
        );

        try {
            $this->clientService->logRequest(
                $request->toArray(),
                Client::API_CHECKOUT_VERSION,
                '/payments',
                $salesChannelContext->getSalesChannelId()
            );

            $response = $paymentsApi->payments($request);

            $this->clientService->logResponse(
                $response->toArray(),
                $salesChannelContext->getSalesChannelId()
            );

            return $response;
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request: %s",
                $exception->getMessage()
            );

            $this->displaySafeErrorMessages($exception);
            $this->logger->error($message);

            throw $exception;
        }
    }

    /**
     * Set browser info on payment request
     *
     * @param IntegrationPaymentRequest $paymentRequest
     * @param array $stateData
     *
     * @return void
     */
    protected function setBrowserInfo(IntegrationPaymentRequest $paymentRequest, array $stateData): void
    {
        if (empty($stateData['browserInfo'])) {
            return;
        }

        $browserInfo = new BrowserInfo();
        $browserInfo->setUserAgent($stateData['browserInfo']['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '');
        $browserInfo->setAcceptHeader($stateData['browserInfo']['acceptHeader'] ?? $_SERVER['HTTP_ACCEPT'] ?? '');
        $browserInfo->setScreenWidth($stateData['browserInfo']['screenWidth']);
        $browserInfo->setScreenHeight($stateData['browserInfo']['screenHeight']);
        $browserInfo->setColorDepth($stateData['browserInfo']['colorDepth']);
        $browserInfo->setTimeZoneOffset($stateData['browserInfo']['timeZoneOffset']);
        $browserInfo->setLanguage($stateData['browserInfo']['language']);
        $browserInfo->setJavaEnabled($stateData['browserInfo']['javaEnabled']);

        $paymentRequest->setBrowserInfo($browserInfo);
    }

    /**
     * Set the delivery address on the payment request
     *
     * @param IntegrationPaymentRequest $paymentRequest
     * @param SalesChannelContext $salesChannelContext
     * @param array $stateData
     *
     * @return void
     */
    protected function setDeliveryAddress(
        IntegrationPaymentRequest $paymentRequest,
        SalesChannelContext $salesChannelContext,
        array $stateData
    ): void {
        if (!empty($stateData['deliveryAddress'])) {
            return;
        }

        $shippingAddress = $salesChannelContext->getShippingLocation()->getAddress();
        if (!$shippingAddress) {
            return;
        }

        $shippingState = 'n/a';

        if ($shippingAddress->getCountryState()) {
            $shippingState = $shippingAddress->getCountryState()->getShortCode();
        }
        $streetData = $this->getSplitStreetAddressHouseNumber($shippingAddress->getStreet() ?? '');

        $addressInfo = new Address();
        $addressInfo->setStreet($streetData['street']);
        $addressInfo->setHouseNumberOrName($streetData['houseNumber']);
        $addressInfo->setPostalCode($shippingAddress->getZipcode());
        $addressInfo->setCity($shippingAddress->getCity());
        $addressInfo->setStateOrProvince($shippingState);
        $addressInfo->setCountry($shippingAddress->getCountry() ? $shippingAddress->getCountry()->getIso() : '');

        $paymentRequest->setDeliveryAddress($addressInfo);
    }

    /**
     * Set the billing address on the payment request
     *
     * @param IntegrationPaymentRequest $paymentRequest
     * @param SalesChannelContext $salesChannelContext
     * @param array $stateData
     *
     * @return void
     */
    protected function setBillingAddress(
        IntegrationPaymentRequest $paymentRequest,
        SalesChannelContext $salesChannelContext,
        array $stateData
    ): void {
        if (!empty($stateData['billingAddress'])) {
            return;
        }

        $billingAddress = $salesChannelContext->getCustomer() ?
            $salesChannelContext->getCustomer()->getActiveBillingAddress() : null;
        if (!$billingAddress) {
            return;
        }

        $billingState = 'n/a';

        if ($billingAddress->getCountryState()) {
            $billingState = $billingAddress->getCountryState()->getShortCode();
        }

        $streetData = $this->getSplitStreetAddressHouseNumber($billingAddress->getStreet() ?? '');

        $addressInfo = new Address();
        $addressInfo->setStreet($streetData['street']);
        $addressInfo->setHouseNumberOrName($streetData['houseNumber']);
        $addressInfo->setPostalCode($billingAddress->getZipcode());
        $addressInfo->setCity($billingAddress->getCity());
        $addressInfo->setStateOrProvince($billingState);
        $addressInfo->setCountry($billingAddress->getCountry() ? $billingAddress->getCountry()->getIso() : '');

        $paymentRequest->setBillingAddress($addressInfo);
    }

    /**
     * Set shopper data on payment request
     *
     * @param IntegrationPaymentRequest $paymentRequest
     * @param SalesChannelContext $salesChannelContext
     * @param array $stateData
     *
     * @return void
     */
    protected function setShopperData(
        IntegrationPaymentRequest $paymentRequest,
        SalesChannelContext $salesChannelContext,
        array $stateData
    ): void {
        $customer = $salesChannelContext->getCustomer();
        if (!$customer) {
            return;
        }

        // Shopper name
        $shopperFirstName = $stateData['shopperName']['firstName'] ?? $customer->getFirstName();
        $shopperLastName = $stateData['shopperName']['lastName'] ?? $customer->getLastName();

        $shopperName = new Name();
        $shopperName->setFirstName($shopperFirstName ?? '');
        $shopperName->setLastName($shopperLastName ?? '');
        $paymentRequest->setShopperName($shopperName);

        // Shopper email
        $shopperEmail = $stateData['shopperEmail'] ?? $customer->getEmail();
        $paymentRequest->setShopperEmail($shopperEmail);

        // Telephone number
        $shopperPhone = $stateData['telephoneNumber'] ??
            ($salesChannelContext->getShippingLocation()->getAddress()
                ? $salesChannelContext->getShippingLocation()->getAddress()->getPhoneNumber() : '');
        if (!empty($shopperPhone)) {
            $paymentRequest->setTelephoneNumber($shopperPhone);
        }

        // Date of birth

        $birthDay = $stateData['dateOfBirthDay'] ?? '';

        if (empty($birthDay) && $customer->getBirthday()) {
            $birthDay = $customer->getBirthday()->format('Y-m-d');
        }

        $paymentRequest->setDateOfBirth($birthDay);

        // Country code

        $countryCode = $stateData['countryCode'];

        if (empty($countryCode) &&
            $customer->getActiveBillingAddress() &&
            $customer->getActiveBillingAddress()->getCountry() &&
            $customer->getActiveBillingAddress()->getCountry()->getIso()
        ) {
            $countryCode = $customer->getActiveBillingAddress()->getCountry()->getIso();
        }

        $paymentRequest->setCountryCode($countryCode ?? '');

        // Shopper locale
        $shopperLocale = $stateData['shopperLocale'] ??
            $this->salesChannelRepository->getSalesChannelLocale($salesChannelContext);
        $paymentRequest->setShopperLocale($shopperLocale);

        // Shopper IP
        $shopperIp = $stateData['shopperIP'] ?? $customer->getRemoteAddress();
        if ($shopperIp) {
            $paymentRequest->setShopperIP($shopperIp);
        }

        // Shopper reference
        $shopperReference = $stateData['shopperReference'] ?? $customer->getId();
        if ($shopperReference) {
            $paymentRequest->setShopperReference($shopperReference);
        }
    }

    /**
     * Set company data on the payment request
     *
     * @param IntegrationPaymentRequest $paymentRequest
     * @param array $billieData
     *
     * @return void
     */
    protected function setCompanyData(IntegrationPaymentRequest $paymentRequest, array $billieData): void
    {
        $companyName = $billieData['companyName'] ?? '';
        $registrationNumber = $billieData['registrationNumber'] ?? '';

        $company = new Company();
        $company->setRegistrationNumber($registrationNumber);
        $company->setName($companyName);

        $paymentRequest->setCompany($company);
    }

    /**
     * Set line items for open invoice payments
     *
     * @param IntegrationPaymentRequest $paymentRequest
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    protected function setLineItems(
        IntegrationPaymentRequest $paymentRequest,
        OrderEntity $orderEntity,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderLines = $orderEntity->getLineItems();
        $lineItems = [];
        $currency = $salesChannelContext->getCurrency();

        foreach ($orderLines->getElements() as $orderLine) {
            if (!in_array($orderLine->getType(), self::ALLOWED_LINE_ITEM_TYPES)) {
                continue;
            }

            $productNumber = $orderLine->getReferencedId();
            $productName = $orderLine->getLabel();
            $price = $orderLine->getPrice();

            $lineTax = $price->getCalculatedTaxes()->getAmount() / $orderLine->getQuantity();
            $taxRate = $price->getCalculatedTaxes()->first();
            $taxRate = !empty($taxRate) ? $taxRate->getTaxRate() : 0;

            $product = !is_null($orderLine->getProductId()) ?
                $this->getProduct($orderLine->getProductId(), $salesChannelContext->getContext()) : null;

            $domainUrl = '';

            if ($salesChannelContext->getSalesChannel()->getDomains() &&
                $salesChannelContext->getSalesChannel()->getDomains()->first() &&
                $salesChannelContext->getSalesChannel()->getDomains()->first()->getUrl()) {
                $domainUrl = $salesChannelContext->getSalesChannel()->getDomains()->first()->getUrl();
            }

            $productUrl = (!is_null($product->getId()) && !is_null($domainUrl)) ?
                sprintf("%s/detail/%s", $domainUrl, $product->getId()) : null;

            $imageUrl = (isset($product) && !is_null($product->getCover())) ?
                $product->getCover()->getMedia()->getUrl() : null;

            $productCategory = (isset($product) &&
                !is_null($product->getCategories()) &&
                $product->getCategories()->count() > 0) ?
                $product->getCategories()->first()->getName() : null;

            $lineItem = new LineItem();
            $lineItem->setDescription($productName);
            $lineItem->setAmountExcludingTax($this->currency->sanitize(
                $price->getUnitPrice() - ($orderEntity->getTaxStatus() === 'gross' ? $lineTax : 0),
                $currency->getIsoCode()
            ));
            $lineItem->setTaxAmount($this->currency->sanitize($lineTax, $currency->getIsoCode()));
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

    /**
     * Get product entity
     *
     * @param string $productId
     * @param Context $context
     *
     * @return ProductEntity|null
     */
    protected function getProduct(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('cover');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('cover.media');

        $productCollection = $this->productRepository->search($criteria, $context);
        $product = $productCollection->get($productId);

        if ($product === null) {
            throw new ProductNotFoundException($productId);
        }

        return $product;
    }

    /**
     * Split street address and house number
     *
     * @param string $address
     *
     * @return array{street: string, houseNumber: string}
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
     * Display safe error messages to user
     *
     * @param AdyenException $exception
     *
     * @return void
     */
    protected function displaySafeErrorMessages(AdyenException $exception): void
    {
        if ('validation' === $exception->getErrorType() &&
            in_array($exception->getAdyenErrorCode(), self::SAFE_ERROR_CODES)) {
            $this->requestStack->getSession()->getFlashBag()->add('warning', $exception->getMessage());
        }
    }
}
