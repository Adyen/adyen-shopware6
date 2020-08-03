<?php declare(strict_types=1);

namespace Adyen\Shopware\Storefront\Controller;

use Shopware\Core\Checkout\Order\SalesChannel\AbstractCancelOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\ContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\AccountOrderController;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoader;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoader;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AdyenAccountOrderController extends AccountOrderController
{

    private $contextSwitchRoute;

    public function __construct(
        AccountOrderController $accountOrderController,
        AccountOrderPageLoader $orderPageLoader,
        AbstractOrderRoute $orderRoute,
        RequestCriteriaBuilder $requestCriteriaBuilder,
        AccountEditOrderPageLoader $accountEditOrderPageLoader,
        ContextSwitchRoute $contextSwitchRoute,
        AbstractCancelOrderRoute $cancelOrderRoute,
        AbstractSetPaymentOrderRoute $setPaymentOrderRoute,
        AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->contextSwitchRoute = $contextSwitchRoute;
        parent::__construct(
            $orderPageLoader,
            $orderRoute,
            $requestCriteriaBuilder,
            $accountEditOrderPageLoader,
            $contextSwitchRoute,
            $cancelOrderRoute,
            $setPaymentOrderRoute,
            $handlePaymentMethodRoute,
            $eventDispatcher
        );
    }

    /**
     * @Route("/account/order/payment/{orderId}", name="frontend.account.edit-order.change-payment-method", methods={"POST"})
     */
    public function orderChangePayment(string $orderId, Request $request, SalesChannelContext $context): Response
    {
        $this->contextSwitchRoute->switchContext(
            new RequestDataBag(
                [
                    SalesChannelContextService::PAYMENT_METHOD_ID => $request->get('paymentMethodId'),
                    'adyenStateData' => $request->get('adyenStateData'),
                    'adyenOrigin' => $request->get('adyenOrigin')
                ]
            ),
            $context
        );
        return $this->redirectToRoute('frontend.account.edit-order.page', ['orderId' => $orderId]);
    }
}
