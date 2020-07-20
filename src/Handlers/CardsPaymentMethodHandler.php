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
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Service\PaymentStateDataService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Util\Currency;
use Adyen\Shopware\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;

class CardsPaymentMethodHandler implements AsynchronousPaymentHandlerInterface
{

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
     * CardsPaymentMethodHandler constructor.
     * @param ConfigurationService $configurationService
     * @param CheckoutService $checkoutService
     * @param Browser $browserBuilder
     * @param Address $addressBuilder
     * @param Payment $paymentBuilder
     * @param Currency $currency
     * @param Customer $customerBuilder
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStateDataService $paymentStateDataService
     * @param SalesChannelRepository $salesChannelRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConfigurationService $configurationService,
        CheckoutService $checkoutService,
        Browser $browserBuilder,
        Address $addressBuilder,
        Payment $paymentBuilder,
        Currency $currency,
        Customer $customerBuilder,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStateDataService $paymentStateDataService,
        SalesChannelRepository $salesChannelRepository,
        LoggerInterface $logger
    ) {
        $this->checkoutService = $checkoutService;
        $this->browserBuilder = $browserBuilder;
        $this->addressBuilder = $addressBuilder;
        $this->currency = $currency;
        $this->configurationService = $configurationService;
        $this->customerBuilder = $customerBuilder;
        $this->paymentBuilder = $paymentBuilder;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->logger = $logger;
    }

    /**
     * @return string
     */
    public function getAdyenPaymentMethodId(): string
    {
        return 'scheme';
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = 'redirect',
        array $gatewayInfo = []
    ): RedirectResponse {
        try {
            $request = $this->preparePaymentsRequest($salesChannelContext, $transaction);
        } catch (Exception $exception) {
            $this->logger->error(
                sprintf(
                    "There was an error with the payment method. Order number: %s Missing data: %s",
                    $transaction->getOrder()->getOrderNumber(),
                    $exception->getMessage()
                )
            );
            exit;
        }

        try {
            $response = $this->checkoutService->payments($request);
        } catch (AdyenException $exception) {
            $this->logger->error(
                sprintf(
                    "There was an error with the /payments request. Order number %s: %s",
                    $transaction->getOrder()->getOrderNumber(),
                    $exception->getMessage()
                )
            );
        }

        return RedirectResponse::create($transaction->getReturnUrl());
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
        // TODO: Implement finalize() method.
    }

    //TODO move to util or outsource to lib

    /**
     * @param string $address
     * @return array
     */
    public function splitStreetAddressHouseNumber(string $address): array
    {
        return [
            'street' => $address,
            'houseNumber' => 'N\A'
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
        $stateData = json_decode($this->paymentStateDataService->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        )->getStateData(), true);

        //Split addresses' house number / name TODO prioritize state.data customer address input when available
        $shippingStreetAddress = $this->splitStreetAddressHouseNumber(
            $salesChannelContext->getShippingLocation()->getAddress()->getStreet()
        );
        $billingStreetAddress = $this->splitStreetAddressHouseNumber(
            $salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet()
        );

        //Get states' codes TODO prioritize state.data customer address input when available
        if ($salesChannelContext->getShippingLocation()->getAddress()->getCountryState()) {
            $shippingState = $salesChannelContext->getShippingLocation()
                ->getAddress()->getCountryState()->getShortCode();
        } else {
            $shippingState = '';
        }
        if ($salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountryState()) {
            $billingState = $salesChannelContext->getCustomer()
                ->getActiveBillingAddress()->getCountryState()->getShortCode();
        } else {
            $billingState = '';
        }

        //Get customer DOB TODO prioritize state.data customer DOB input when available
        if ($salesChannelContext->getCustomer()->getBirthday()) {
            $customerBirthday = $salesChannelContext->getCustomer()->getBirthday()->format('dd-mm-yyyy');
        } else {
            $customerBirthday = '';
        }

        //Setting browser info
        if (empty($stateData['browserInfo']['acceptHeader'])) {
            $stateData['browserInfo']['acceptHeader'] = $_SERVER['HTTP_ACCEPT'];
        }
        if (empty($stateData['browserInfo']['userAgent'])) {
            $stateData['browserInfo']['acceptHeader'] = $_SERVER['HTTP_USER_AGENT'];
        }

        //Validate state.data for payment and build request object
        $request = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);

        $request = $this->addressBuilder->buildDeliveryAddress(
            $shippingStreetAddress['street'],
            $shippingStreetAddress['houseNumber'],
            $salesChannelContext->getShippingLocation()->getAddress()->getZipcode(),
            $salesChannelContext->getShippingLocation()->getAddress()->getCity(),
            $shippingState,
            $salesChannelContext->getShippingLocation()->getAddress()->getCountry()->getIso(),
            $request
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
        $request = $this->paymentBuilder->buildPaymentData(
            $salesChannelContext->getCurrency()->getIsoCode(),
            $this->currency->sanitize(
                $transaction->getOrder()->getPrice()->getTotalPrice(),
                $salesChannelContext->getCurrency()->getIsoCode()
            ),
            $transaction->getOrder()->getOrderNumber(),
            $this->configurationService->getMerchantAccount(),
            $transaction->getReturnUrl(),
            $request
        );
        $request = $this->customerBuilder->buildCustomerData(
            false,
            $salesChannelContext->getCustomer()->getEmail(),
            $salesChannelContext->getShippingLocation()->getAddress()->getPhoneNumber(),
            '',
            $customerBirthday,
            $salesChannelContext->getCustomer()->getFirstName(),
            $salesChannelContext->getCustomer()->getLastName(),
            $salesChannelContext->getShippingLocation()->getAddress()->getCountry()->getIso(),
            $this->salesChannelRepository->getSalesChannelAssocLocale($salesChannelContext)
                ->getLanguage()->getLocale()->getCode(),
            $salesChannelContext->getCustomer()->getRemoteAddress(),
            $salesChannelContext->getCustomer()->getId(),
            $request
        );
        $request['origin'] = $this->paymentStateDataService->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        )->getOrigin();

        return $request;
    }
}
