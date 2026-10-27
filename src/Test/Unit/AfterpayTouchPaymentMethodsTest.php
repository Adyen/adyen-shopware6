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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Handlers\AfterpayTouchPaymentMethodHandler;
use Adyen\Shopware\Handlers\AfterpayTouchUsPaymentMethodHandler;
use Adyen\Shopware\PaymentMethods\AfterpayTouchPaymentMethod;
use Adyen\Shopware\PaymentMethods\AfterpayTouchUsPaymentMethod;
use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\Test\Common\AdyenTestCase;

class AfterpayTouchPaymentMethodsTest extends AdyenTestCase
{
    public function testAfterpayTouchPaymentMethodCode()
    {
        // The code is matched case-sensitively against the /paymentMethods response
        $this->assertSame('afterpaytouch', AfterpayTouchPaymentMethodHandler::getPaymentMethodCode());
        $this->assertSame('afterpaytouch_US', AfterpayTouchUsPaymentMethodHandler::getPaymentMethodCode());
    }

    public function testAfterpayTouchHandlersAreOpenInvoice()
    {
        $this->assertTrue(AfterpayTouchPaymentMethodHandler::$isOpenInvoice);
        $this->assertTrue(AfterpayTouchPaymentMethodHandler::$supportsManualCapture);
        $this->assertTrue(AfterpayTouchUsPaymentMethodHandler::$isOpenInvoice);
        $this->assertTrue(AfterpayTouchUsPaymentMethodHandler::$supportsManualCapture);
    }

    public function testAfterpayTouchPaymentMethod()
    {
        $paymentMethod = new AfterpayTouchPaymentMethod();

        $this->assertSame('Afterpay', $paymentMethod->getName());
        $this->assertSame(AfterpayTouchPaymentMethodHandler::class, $paymentMethod->getPaymentHandler());
        $this->assertSame('ADYEN_AFTERPAYTOUCH', $paymentMethod->getGatewayCode());
        $this->assertNull($paymentMethod->getTemplate());
        $this->assertSame('afterpaytouch.png', $paymentMethod->getLogo());
        $this->assertSame('redirect', $paymentMethod->getType());
    }

    public function testAfterpayTouchUsPaymentMethod()
    {
        $paymentMethod = new AfterpayTouchUsPaymentMethod();

        $this->assertSame('Cash App Afterpay', $paymentMethod->getName());
        $this->assertSame(AfterpayTouchUsPaymentMethodHandler::class, $paymentMethod->getPaymentHandler());
        $this->assertSame('ADYEN_AFTERPAYTOUCH_US', $paymentMethod->getGatewayCode());
        $this->assertNull($paymentMethod->getTemplate());
        // The logo file name is case-sensitive on the Adyen CDN
        $this->assertSame('afterpaytouch_US.png', $paymentMethod->getLogo());
        $this->assertSame('redirect', $paymentMethod->getType());
    }

    public function testAfterpayTouchPaymentMethodsAreRegistered()
    {
        $this->assertContains(AfterpayTouchPaymentMethod::class, PaymentMethods::PAYMENT_METHODS);
        $this->assertContains(AfterpayTouchUsPaymentMethod::class, PaymentMethods::PAYMENT_METHODS);
    }
}
