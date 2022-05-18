<?php
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
 * Copyright (c) 2022 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Service\Builder\Address;
use Adyen\Service\Builder\Browser;
use Adyen\Service\Builder\Customer;
use Adyen\Service\Builder\Payment;
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\CurrencyNotFoundException;
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Storefront\Controller\RedirectResultController;
use Adyen\Util\Currency;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class PaymentRequestService
{
    /**
     * Error codes that are safe to display to the shopper.
     * @see https://docs.adyen.com/development-resources/error-codes
     */
    const SAFE_ERROR_CODES = ['124'];
    const PROMOTION = 'promotion';

    private Session $session;
    private EntityRepositoryInterface $currencyRepository;
    private EntityRepositoryInterface $productRepository;
    private Currency $currency;
    private Browser $browserBuilder;
    private Address $addressBuilder;
    private Customer $customerBuilder;
    private Payment $paymentBuilder;
    private SalesChannelRepository $salesChannelRepository;
    private ConfigurationService $configurationService;
    private RouterInterface $router;
    private CsrfTokenManagerInterface $csrfTokenManager;
    private CheckoutStateDataValidator $checkoutStateDataValidator;

    public function __construct(
        Address $addressBuilder,
        Browser $browserBuilder,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        ConfigurationService $configurationService,
        Currency $currency,
        Customer $customerBuilder,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $productRepository,
        Payment $paymentBuilder,
        RouterInterface $router,
        SalesChannelRepository $salesChannelRepository,
        Session $session
    ) {
        $this->session = $session;
        $this->currencyRepository = $currencyRepository;
        $this->productRepository = $productRepository;
        $this->browserBuilder = $browserBuilder;
        $this->addressBuilder = $addressBuilder;
        $this->customerBuilder = $customerBuilder;
        $this->paymentBuilder = $paymentBuilder;
        $this->currency = $currency;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configurationService = $configurationService;
        $this->router = $router;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
    }

    public function buildPaymentRequest(
        array $request,
        SalesChannelContext $salesChannelContext,
        string $paymentMethodHandler,
        float $totalPrice,
        string $reference,
        string $returnUrlQuery,
        ?array $lineItems = null
    ): array {
        //Validate state.data for payment and build request object
        $request = $this->checkoutStateDataValidator->getValidatedAdditionalData($request);

        if (!empty($request['additionalData'])) {
            $stateDataAdditionalData = $request['additionalData'];
        }

        /** @var AbstractPaymentMethodHandler $paymentMethodHandler */
        if (empty($request['paymentMethod']['type'])) {
            $paymentMethodType = $paymentMethodHandler::getPaymentMethodCode();
        } else {
            $paymentMethodType = $request['paymentMethod']['type'];
        }

        if ($paymentMethodHandler::$isGiftCard) {
            $request['paymentMethod']['brand'] = $paymentMethodHandler::getBrand();
        }

        if (!empty($request['storePaymentMethod']) && $request['storePaymentMethod'] === true) {
            $request['recurringProcessingModel'] = 'CardOnFile';
            $request['shopperInteraction'] = 'Ecommerce';
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
                $billingState = '';
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
                $totalPrice,
                $salesChannelContext->getCurrency()->getIsoCode()
            ),
            $reference,
            $this->configurationService->getMerchantAccount($salesChannelContext->getSalesChannel()->getId()),
            $this->getAdyenReturnUrl($returnUrlQuery),
            $request
        );

        $request = $this->paymentBuilder->buildAlternativePaymentMethodData(
            $paymentMethodType,
            '',
            $request
        );

        if ($paymentMethodHandler::$isOpenInvoice) {
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

        return $request;
    }

    /**
     * Creates the Adyen Redirect Result URL with the same query as the original return URL
     * Fixes the CSRF validation bug: https://issues.shopware.com/issues/NEXT-6356
     *
     * @param string $returnUrlQuery
     * @return string
     */
    public function getAdyenReturnUrl(string $returnUrlQuery): string
    {
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

    /**
     * @param string $address
     * @return array
     */
    public function getSplitStreetAddressHouseNumber(string $address): array
    {
        $streetFirstRegex = '/(?<streetName>[\w\W]+)\s+(?<houseNumber>\d{1,10}((\s)?\w{1,3})?)$/m';
        $numberFirstRegex = '/^(?<houseNumber>\d{1,10}((\s)?\w{1,3})?)\s+(?<streetName>[\w\W]+)/m';

        preg_match($streetFirstRegex, $address, $streetFirstAddress);
        preg_match($numberFirstRegex, $address, $numberFirstAddress);

        if ($streetFirstAddress) {
            return [
                'street' => $streetFirstAddress['streetName'],
                'houseNumber' => $streetFirstAddress['houseNumber']
            ];
        }
        else if ($numberFirstAddress) {
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
     * @param string $currencyId
     * @param Context $context
     * @return CurrencyEntity
     */
    public function getCurrency(string $currencyId, Context $context): CurrencyEntity
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
    public function getProduct(string $productId, Context $context): ProductEntity
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

    public function displaySafeErrorMessages(AdyenException $exception)
    {
        if ('validation' === $exception->getErrorType()
            && in_array($exception->getAdyenErrorCode(), self::SAFE_ERROR_CODES)) {
            $this->session->getFlashBag()->add('warning', $exception->getMessage());
        }
    }
}