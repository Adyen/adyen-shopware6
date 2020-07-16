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
use Psr\Log\LoggerInterface;


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
    protected $logger;

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
        $this->logger = $logger;
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
        try {
            $request = $this->preparePaymentsRequest($salesChannelContext, $transaction);
        } catch (MissingDataException $exception) {
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
     * @inheritDoc
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // TODO: Implement finalize() method.
    }

    //TODO move to util or outsource to lib
    public function splitStreetAddressHouseNumber(string $address): array
    {
        $fullStreetAddress = explode(' ', $address);
        $streetAddress = implode(' ', array_slice($fullStreetAddress, 0, -1));
        $houseNumberOrName = end($fullStreetAddress);

        return [
            'street' => $streetAddress,
            'houseNumber' => $houseNumberOrName
        ];
    }

    public function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction
    ) {
        //Split addresses' house number / name
        $shippingStreetAddress = $this->splitStreetAddressHouseNumber(
            $salesChannelContext->getShippingLocation()->getAddress()->getStreet()
        );
        $billingStreetAddress = $this->splitStreetAddressHouseNumber(
            $salesChannelContext->getCustomer()->getActiveBillingAddress()->getStreet()
        );

        //Get states' codes
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

        //Get customer DOB
        if ($salesChannelContext->getCustomer()->getBirthday()) {
            $customerBirthday = $salesChannelContext->getCustomer()->getBirthday()->format('dd-mm-yyyy');
        } else {
            $customerBirthday = '';
        }

        //Validate state.data for payment
        //TODO replace with stateData stored by generic component branch
        $stateDataJson = '{
                              "riskData": {
                                "clientData": "eyJ2ZXJzaW9uIjoiMS4wLjAiLCJkZXZpY2VGaW5nZXJwcmludCI6InJ5RUdYOGVacEowMDMwMDAwMDAwMDAwMDAwS1piSVFqNmt6czAyMDAwNzQ3NzZjVkI5NGlLekJHWDZRcVhPMlhLNzVTMTZHb2g1TWswMDRJR1lOQ3NqNnlJMDAwMDBxWmtURTAwMDAwRDBUY1U2a05pTEVDNEZsU0FCbVE6NDAiLCJwZXJzaXN0ZW50Q29va2llIjpbIl9ycF91aWQ9YWFiYzA1ZWItZGQ1Mi1mMGEyLTI5NTctYjM5NzFmZDBlNzM1Il19"
                              },
                              "paymentMethod": {
                                "type": "scheme",
                                "holderName": "Joao Smith",
                                "encryptedCardNumber": "adyenjs_0_1_25$EP+a8p2+Z5h5Ez256FVfvDLbCsSf0XU6iQfWPirAb0ruO2VcEICgWt6R6n8hES8OJsbEKOdNxPd2AY7lZq0v4nVNRR+9Ffh6stMLC+4crbF41vxBLIq0NtD9XrMVzgE+FcpPtlolsBIlYkCo1C5pNeJPyIXBcgjLQvG38v0unuwTnXNOolV49ltyax8CN99eYulrW9uQxM7iXB04edlNLvXExtOOCUgCTUwF3ITIxIIue+YgLQ9S9a17h7w2eNVSxRJcFJkna/yVfUSpXG9X2jV+Si6EQcxdzT3GwtIKzEOmer0x1oDggz8dXyjQsB5gsYHonI2Lf6rdYVEmKruVZQ==$0D0teWnJGiyhkyGu8SB0G6+Yb1j96SaZsYoKp8vW1OIZ/+Nm0TOA17XyYnCFEXO/Fp1pi1/34PBraQ+X8W6lJkDZBb2tGehKyvqhKotsRVWARUceCVJT1poT9osMzxvqrzfUIFfWXoAACuPafxkRRNUWWpJz8QqE1rBVjDLmzyqR/+m98MsWf019HArZeQDi2nRIi4ct9xGLzeuLv81Bexyaq/GCwFPFfDEUYj4T6oCrqfELV2BNypze1VKDOqILE+gGu+6imlGxwP6xAOyZbgi6Y7j7UJ8/6A16rm6pltMLR7lSxywa7Fxxy2KmmGn4E9iRuxQYU1e8+Fie7V2fNS1Wc+SnYU1Tkb6w1cd2fcRxyr2LTngEMycbcb6ApnsKJgRc0Gd92brJu4DDBQG/zC3YancCcPWojsJmRuPOf3l0ZesMMuEyIfX3MjUhh4BaRPK9xwvNUHnRqo0DsdHsrRzpMKTlMLia8bkUqsFGsvAQuL5NFVeyghl0DjijY7qamTDfSFz49oKbeeKqqrrDV478qaZQRLy4qm/6aUK7TWAMVW3Z9RltZBqLWN8rS7ayMYbojbW9voXfGBHZz4UKnIRCZWq+sAuBeJs2eTaS8PsHm5xjqcRrfJL/4hv5n/J3RX+bmZCamyImA4CCwV6ElRTzh1Zrxw/TYp9rd9j9m0bFTCNw7JpB78rT/613SUCv6R0DDN7KZM0OI8dOoP/2XPOMB3vcpP4vuIDebi6ENnINpCsux1BcB+8Sgl4vPM2WARQUnetZKFVf+/t9ejumYxNy75bUsIaYI+Gr4wZPx4xZhIKZnFfikH9Yp2/SH7ChzdUBGl3ej0uPgzFx0PYGSSHB4lHcmshTk+0=",
                                "encryptedExpiryMonth": "adyenjs_0_1_25$evHjqj09SNa0kzXvNOv6g13OfrrdmkvHFklel5COAVUmAbgCpqE4Jxxd7Cfh9a7elZoucPQNiqlZ9fuwVR5rcWP2i0pbEIh6z0HZSG9we/CBhxXNG1kYh7j2T5CPzaSb1rr/1XYcqNwQnbCt/+0yvS2W6NdxbqDYq1ZiZfK0+L2j3rOunZGAnbKGVEpUCjBXmSBF5diiSIur7npRnxEH88F5UfiYHLptOx3OjutHS9k2SyW45cxWIvTXLjoGe/7HNw+OUBoz/fzn1xZC60ac33M1WubSwOLy1NA9zouhfha12QLe024JUY+CMYlrthXvuzdVUK2Ut6fRNqLEVNSTNw==$DCZv/ZJSLRj8LUCMkq9OyNgKUvpNSPg2myEg9+VCTsTRLe0R8RKRU9o6cJw1/VmXZlG1wkZcwN+g9PdiHtd0t3SFraKgteq/woD1jbIjlbicX+uARRWfdWxNrvlzQx0Rh6+JfkSh1L5r/DAzfmKyLWSAyYXQ6EJFlVt4gYpH6cjmEFsZ75kjgL67YVY46Aakh3uqAB6MttMxmx1COo5BpkCaG6Hbno71Wnr/q23LkqrmQpyNs5NxpK1LsIrMqOJpHo2xjJQODZbCb8kTWSUii1y9haREh1ITj9ubxmIbyAi1dqRyos03Zt4VKx29sgXEV1JCt1xFYuRw6/7/WW+k4jOac0fBMeWDYrrxhelg1hNzwUE5fOih9g9ViqTZ35QXqjxRCQTDNxCXJqljux8tDukF5ITNtmp93tMajEZcH5vNhl2vWc/TC7y+iWRtzKcFXcOBReUZ3QY+x9aOpQ3X",
                                "encryptedExpiryYear": "adyenjs_0_1_25$RYU6vdCMNTkG8swQPl3DvCIgA+3DAQeeP5XWgf2KHf49sWzRy/4VLYfvMLpuAfVVnanqIsnpMVWXyHXvsA3R6IXh4HeDYr0pMfZGkcmDcHyw48Xo97j0yBZt4+hOFPgFfWmtA9zE9j90IzG5Sy6oDjMnLcUgTY3QAB2CCcs9Wn9oPlAeT3FQbj5BaHMeG0e8JvNdqLgEwhr48DiiaDwMKZpxUICB9TpwktlRav5KHvq2/RM7CyPMEmuFxJL2v3sOhcKmNwG7NCbZJPdFuQwE9gMqvf8wqxYXCPJQSp9G0HkwyI+oVgKIwB5NuiYp/D+qeQH1ixvg0LrCoDUcz34H8w==$nBYXMaTJJoX6i5GktBQ7DS1zD7jU5joPoWF9xIZvOnVrU5lCuWjLzEdDTnihTWDWKcff1WdtxhPmZs/OGfdINcILQPzmv2TwRvDthBdMlvCKVa5ArtMo4XQIpS6HYN1OyhCsBhl8U4Dl3GvesshenTFpBXF9GGAHJOefDeTmFeii0kCwt5gIbP61LAr8l454Gz9wx8bUihbO2IE1N3f29eux0hohkRv4mQTdANmowxwXdLcDbwrlbqb5eYDSQbAn66ZLV7splKnHypcz3rqHnPPhLqtI4gIiyr6Dn/Y/jGRU3BXLoHiRXM3Je82D2r0gltR8U1W9i+UMf8BKhavZA8T8vU3/d32Kpwu40EvP5TR5FUyk+Z6gxzX2FQLUeruQaZBOuDRQ72kcrfSeUz4lCHo2g+YRG17rxZhp7tV/EXoC5Y97NXeSHJkNphMHiH9NWvh137ILZIzjLu6C7Qiq3g==",
                                "encryptedSecurityCode": "adyenjs_0_1_25$HPd6XjRG2b3RsunHCgqJbDPAXWJ6FH8HHzX9P5yYve6QiqQxTkVSRcvhw5LCdiB2fic0UhuQvhY9GZMCvRlz6Cx/5vQ7USC12haFCpKDlRMgv2c0RZMZwDSSXNCIkhsh2kwhNJwZ55ZvBdQjCeDMjgrk1sGdfGXnKXaY4cc9cxkJpLYa29bImKoLwSE10h36sDQ8xONnftiuFHA4ba6Q6MuJahkF5DnqzJyrAkLBIP9aVywb3XhLzKdEi/+q/E9Gz2WjL9CrhqVfeiUym424NmiZvumpYtT1DZCrC1Rb6i7G3N/os7iGzkqJ5EKq7bTFOSsi6zS6bEVoQVsE6Jw9yA==$6sw2JNCRcV2khILHKelRwwWqeFbolB7MWZTaoBOCVfTpAQuP981ESMLyy6mShEGprwaIt7UvRo+jd2vhP3ECKmVATBACZXR/EgVKoJwdJhdSY2zIW4axAjVsjA/nup18PgSHrIBH1Ru04yLU5wnX0lqIW23glydzU28Vo7gWQFQYQH/+3cNL6L1sv6RGIYwh6mXbOtNsL68+w0IRP45ztms8o73OCAu48aR8mBVBcLCskYw7oGzxovwjPo+5puWR7QPlL4uNJWRSGjUN5CBsR7L67SIi3FIrnXsvmhumwsrLWq0Ml1f68w7xm1QLnjR4qEvDX5fYHBCKVKQgGKvvVU6Js5MaFkVhBV6uT9BouAHCRPEfWRC/mg6q9fx14pcqbqEDIyQyFLElOTo9cB0trTa2gGtfG8BbgvnSE1czuYv4pTs5k7QlC0wsbf1mrgMIfipCG8lCysk="
                              },
                              "storePaymentMethod": false,
                              "installments": {
                                "value": 2
                              },
                              "browserInfo": {
                                "acceptHeader": "*/*",
                                "colorDepth": 24,
                                "language": "pt",
                                "javaEnabled": false,
                                "screenHeight": 2160,
                                "screenWidth": 3840,
                                "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/84.0.4147.89 Safari/537.36",
                                "timeZoneOffset": -120
                              }
                            }';
        $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData(json_decode($stateDataJson
            //$this->paymentStateDataService->getPaymentStateDataFromContextToken($salesChannelContext->getToken())
            , true
        ));

        //Build request
        $request = array();
        $request = $this->browserBuilder->buildBrowserData(
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_ACCEPT'],
            $stateData['browserInfo']['screenWidth'],
            $stateData['browserInfo']['screenHeight'],
            $stateData['browserInfo']['colorDepth'],
            $stateData['browserInfo']['timeZoneOffset'],
            $stateData['browserInfo']['language'],
            $stateData['browserInfo']['javaEnabled'],
            $request
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
        $request = $this->paymentBuilder->buildCardData(
            $stateData['paymentMethod']['encryptedCardNumber'],
            $stateData['paymentMethod']['encryptedExpiryMonth'],
            $stateData['paymentMethod']['encryptedExpiryYear'],
            $stateData['paymentMethod']['holderName'],
            'http://192.168.33.10', //TODO replace with util function in generic component branch
            $stateData['paymentMethod']['encryptedSecurityCode'],
            $stateData['paymentMethod']['type'],
            $stateData['storePaymentMethod'],
            $request
        );

        return $request;
    }
}
