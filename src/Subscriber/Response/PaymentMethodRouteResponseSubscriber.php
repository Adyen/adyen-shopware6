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

namespace Adyen\Shopware\Subscriber\Response;

use Adyen\Shopware\Provider\AdyenPluginProvider;
use Adyen\Shopware\Service\PaymentMethodsFilterService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Struct\AdyenPaymentMethodDataStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

class PaymentMethodRouteResponseSubscriber implements EventSubscriberInterface, ResetInterface
{
    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;

    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * @var PaymentMethodsFilterService
     */
    private $paymentMethodsFilterService;

    /**
     * @var array
     */
    private $paymentMethodsResponse;

    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        PaymentMethodsService $paymentMethodsService,
        PaymentMethodsFilterService $paymentMethodsFilterService
    ) {
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentMethodsFilterService = $paymentMethodsFilterService;
    }

    public static function getSubscribedEvents()
    {
        return [
            // Ensure event is executed before "Shopware\Core\System\SalesChannel\Api\StoreApiResponseListener".
            KernelEvents::RESPONSE => ['adjustPaymentMethodsResponse', 11000],
        ];
    }

    public function adjustPaymentMethodsResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof PaymentMethodRouteResponse) {
            return;
        }

        $context = $this->getSalesChannelContext($event->getRequest());
        if (null === $context) {
            return;
        }

        $this->filterPaymentMethods($context, $event);
        $this->extendPaymentMethodsData($context, $response);
    }

    public function reset()
    {
        $this->paymentMethodsResponse = null;
    }

    private function filterPaymentMethods(SalesChannelContext $context, ResponseEvent $event): void
    {
        if (true !== $event->getRequest()->query->getBoolean('onlyAvailable', false)) {
            return;
        }

        $methods = $this->paymentMethodsFilterService->filterShopwarePaymentMethods(
            $event->getResponse()->getPaymentMethods(),
            $context,
            $this->adyenPluginProvider->getAdyenPluginId()
        );

        $response = new PaymentMethodRouteResponse($methods);
        $event->setResponse($response);
    }

    private function extendPaymentMethodsData(SalesChannelContext $context, PaymentMethodRouteResponse $response): void
    {
        $methods = $response->getPaymentMethods();
        foreach ($methods as $method) {
            $extension = new AdyenPaymentMethodDataStruct();
            $method->addExtension('adyenData', $extension);

            if ($method->getPluginId() !== $this->adyenPluginProvider->getAdyenPluginId()) {
                continue;
            }

            $type = $this->getPaymentMethodType($method);

            $extension->setIsAdyen(true);
            $extension->setType($type);

            if (!empty($type)) {
                $extension->setPaymentMethodsResponse($this->getPaymentMethodsResponseForType($context, $type));
            }
        }
    }

    private function getPaymentMethodsResponseForType(SalesChannelContext $context, string $type)
    {
        $paymentMethodsResponse = $this->getPaymentMethodsResponse($context);
        if (empty($paymentMethodsResponse['paymentMethods'])) {
            return $paymentMethodsResponse;
        }

        $paymentMethodsResponse['paymentMethods'] = array_filter(
            $paymentMethodsResponse['paymentMethods'],
            function ($value) use ($type) {
                return ($value['type'] ?? null) == $type;
            }
        );

        return $paymentMethodsResponse;
    }

    private function getPaymentMethodType(PaymentMethodEntity $method): ?string
    {
        $callable = [$method->getHandlerIdentifier(), 'getPaymentMethodCode'];
        if (!is_callable($callable)) {
            return null;
        }

        return call_user_func($callable);
    }

    private function getPaymentMethodsResponse(SalesChannelContext $context): array
    {
        if (isset($this->paymentMethodsResponse)) {
            return $this->paymentMethodsResponse;
        }

        $this->paymentMethodsResponse = $this->paymentMethodsService->getPaymentMethods($context);

        return $this->paymentMethodsResponse;
    }

    private function getSalesChannelContext(Request $request): ?SalesChannelContext
    {
        return $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
    }
}
