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
use Adyen\Exception\MissingDataException;
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


class CardsPaymentMethodHandler implements AsynchronousPaymentHandlerInterface
{

    protected $checkoutService;
    protected $browserBuilder;
    protected $addressBuilder;
    protected $paymentBuilder;
    protected $currency;
    protected $configurationService;
    protected $customerBuilder;
    protected $checkoutStateDataValidator;
    protected $paymentStateDataService;

    public function __construct(
        CheckoutService $checkoutService,
        Browser $browserBuilder,
        Address $addressBuilder,
        Payment $paymentBuilder,
        Currency $currency,
        ConfigurationService $configurationService,
        Customer $customerBuilder,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStateDataService $paymentStateDataService

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
    }


    /**
     * {@inheritDoc}
     *
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
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = 'redirect',
        array $gatewayInfo = []
    ): RedirectResponse {

        $request = array();

        try {

            $fullShippingStreetAddress = explode(' ',
                $salesChannelContext->getShippingLocation()->getAddress()->getStreet());
            $shippingStreet = $fullShippingStreetAddress[0];
            $shippingHouseNumberOrName = end($fullShippingStreetAddress);

            $fullBillingStreetAddress = explode(' ',
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet());
            $billingStreet = $fullBillingStreetAddress[0];
            $billingHouseNumberOrName = end($fullBillingStreetAddress);

            if ($salesChannelContext->getShippingLocation()->getAddress()->getCountryState()) {
                $shippingState = $salesChannelContext->getShippingLocation()->getAddress()->getCountryState()->getShortCode();
            } else {
                $shippingState = '';
            }

            if ($salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountryState()) {
                $billingState = $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountryState()->getShortCode();
            } else {
                $billingState = '';
            }

            if ($salesChannelContext->getCustomer()->getBirthday()) {
                $customerBirthday = $salesChannelContext->getCustomer()->getBirthday()->format('dd-mm-yyyy');
            } else {
                $customerBirthday = '';
            }

            $request = $this->browserBuilder->buildBrowserData(
                $_SERVER["HTTP_USER_AGENT"],
                $_SERVER["HTTP_ACCEPT"],
                0,
                0,
                0,
                0,
                '',
                false,
                $request
            );
            $request = $this->addressBuilder->buildDeliveryAddress(
                $shippingStreet,
                $shippingHouseNumberOrName,
                $salesChannelContext->getShippingLocation()->getAddress()->getZipcode(),
                $salesChannelContext->getShippingLocation()->getAddress()->getCity(),
                $shippingState,
                $salesChannelContext->getShippingLocation()->getAddress()->getCountry()->getIso(),
                $request
            );
            $request = $this->addressBuilder->buildBillingAddress(
                $billingStreet,
                $billingHouseNumberOrName,
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getZipcode(),
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCity(),
                $billingState,
                $salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountry()->getIso(),
                $request
            );
            $request = $this->paymentBuilder->buildPaymentData(
                $salesChannelContext->getCurrency()->getIsoCode(),
                $this->currency->sanitize($transaction->getOrder()->getPrice()->getTotalPrice(),
                    $salesChannelContext->getCurrency()->getIsoCode()),
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
                'en-GB', //TODO replace with function in generic component branch
                $salesChannelContext->getCustomer()->getRemoteAddress(),
                $salesChannelContext->getCustomer()->getId(),
                $request
            );
            $request = $this->buildCardData($request);

            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData(json_decode($this->paymentStateDataService->getPaymentStateDataFromContextToken($salesChannelContext->getToken())));


        } catch (MissingDataException $exception) {
            throw new MissingDataException('somethings missing yo');
        }


        try {
            $response = $this->checkoutService->payments($request);
        } catch (AdyenException $e) {
            throw new AdyenException($e->getMessage());

        }


        //$this->checkoutService->payments()
        throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'Testing payment method');
    }

    /**
     * @inheritDoc
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // TODO: Implement finalize() method.
    }

    public function buildCardData(
        $encryptedCardNumber,
        $encryptedExpiryMonth,
        $encryptedExpiryYear,
        $holderName,
        $origin,
        $encryptedSecurityCode = '',
        $paymentMethodType = 'scheme',
        $storePaymentMethod = false,
        $request = array()
    ) {
        $request['paymentMethod']['type'] = $paymentMethodType;
        $request['paymentMethod']['encryptedCardNumber'] = $encryptedCardNumber;
        $request['paymentMethod']['encryptedExpiryMonth'] = $encryptedExpiryMonth;
        $request['paymentMethod']['encryptedExpiryYear'] = $encryptedExpiryYear;
        $request['paymentMethod']['holderName'] = $holderName;

        // Security code is not required for all card types
        if (!empty($encryptedSecurityCode)) {
            $request['paymentMethod']['encryptedSecurityCode'] = $encryptedSecurityCode;
        }

        // Store card details required fields
        if (true == $storePaymentMethod) {
            $request['storePaymentMethod'] = true;
            $request['shopperInteraction'] = 'Ecommerce';
        }

        return $request;
    }
}
