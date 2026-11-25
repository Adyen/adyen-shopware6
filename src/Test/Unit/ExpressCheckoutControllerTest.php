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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */
namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Controller\StoreApi\ExpressCheckout\ExpressCheckoutController;
use Adyen\Shopware\Exception\IncompleteExpressCheckoutCustomerDataException;
use Adyen\Shopware\Exception\MissingExpressCheckoutCustomerDataException;
use Adyen\Shopware\Service\ExpressCheckoutService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ExpressCheckoutControllerTest extends AdyenTestCase
{
    private const SHOPPER_ADDRESS = [
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'street' => 'Main Street 1',
        'zipcode' => '10115',
        'city' => 'Berlin',
        'countryCode' => 'DE',
    ];

    private MockObject $expressCheckoutService;
    private ExpressCheckoutController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expressCheckoutService = $this->getSimpleMock(ExpressCheckoutService::class);
        $this->controller = new ExpressCheckoutController($this->expressCheckoutService);
    }

    /**
     * The page was rendered for a logged-in customer whose session is lost since: the payment sheet did not ask for
     * the shopper data, so no guest customer may be created from fallback data.
     */
    public function testCreateCartWithoutCustomerAndShopperDataThrows(): void
    {
        $this->expressCheckoutService->expects($this->never())->method('createCart');
        $this->expectException(MissingExpressCheckoutCustomerDataException::class);

        $this->controller->createCart($this->requestData([], ''), $this->context(null));
    }

    /**
     * The shopper data is checked for presence only, so that no part of it is filled with fallback data.
     *
     * @dataProvider incompleteShopperDataProvider
     */
    public function testCreateCartWithoutCustomerAndIncompleteShopperDataThrows(array $newAddress, string $email): void
    {
        $this->expressCheckoutService->expects($this->never())->method('createCart');
        $this->expectException(IncompleteExpressCheckoutCustomerDataException::class);

        $this->controller->createCart($this->requestData($newAddress, $email), $this->context(null));
    }

    public function incompleteShopperDataProvider(): array
    {
        return [
            'no e-mail' => [self::SHOPPER_ADDRESS, ''],
            'no first name' => [array_merge(self::SHOPPER_ADDRESS, ['firstName' => '']), 'jane@example.com'],
            'no last name' => [array_merge(self::SHOPPER_ADDRESS, ['lastName' => '']), 'jane@example.com'],
            'no street' => [array_merge(self::SHOPPER_ADDRESS, ['street' => '']), 'jane@example.com'],
            'e-mail only' => [[], 'jane@example.com'],
        ];
    }

    public function testCreateCartWithoutCustomerCreatesGuestFromShopperData(): void
    {
        $context = $this->context(null);
        $this->expressCheckoutService->expects($this->once())
            ->method('createCart')
            ->with(
                'product-id',
                1,
                $context,
                self::SHOPPER_ADDRESS,
                [],
                'handler',
                'jane@example.com',
                true,
                false
            )
            ->willReturn(['cart' => 'cart']);

        $result = $this->controller->createCart(
            $this->requestData(self::SHOPPER_ADDRESS, 'jane@example.com'),
            $context
        );

        $this->assertSame(['cart' => 'cart'], $result);
    }

    /**
     * A logged-in customer is charged with their own data, so the sheet does not ask for any.
     */
    public function testCreateCartForLoggedInCustomerWithoutShopperData(): void
    {
        $context = $this->context($this->customer(false));
        $this->expressCheckoutService->expects($this->once())
            ->method('createCart')
            ->with('product-id', 1, $context, [], [], 'handler', '', false, false)
            ->willReturn(['cart' => 'cart']);

        $this->controller->createCart($this->requestData([], ''), $context);
    }

    /**
     * A guest customer of an earlier express order keeps its data when the sheet sends none.
     */
    public function testCreateCartForGuestCustomerInContextWithoutShopperData(): void
    {
        $context = $this->context($this->customer(true));
        $this->expressCheckoutService->expects($this->once())
            ->method('createCart')
            ->with('product-id', 1, $context, [], [], 'handler', '', false, true)
            ->willReturn(['cart' => 'cart']);

        $this->controller->createCart($this->requestData([], ''), $context);
    }

    private function requestData(array $newAddress, string $email): RequestDataBag
    {
        return new RequestDataBag([
            'productId' => 'product-id',
            'quantity' => 1,
            'formattedHandlerIdentifier' => 'handler',
            'newAddress' => $newAddress,
            'newShippingMethod' => [],
            'email' => $email,
        ]);
    }

    private function context(?CustomerEntity $customer): SalesChannelContext
    {
        $context = $this->getSimpleMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }

    private function customer(bool $guest): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setGuest($guest);

        return $customer;
    }
}
