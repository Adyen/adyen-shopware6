<?php declare(strict_types=1);

namespace Adyen\Shopware\Storefront\Controller;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\ContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AdyenAccountOrderController extends StorefrontController
{

    private $contextSwitchRoute;

    public function __construct(
        ContextSwitchRoute $contextSwitchRoute
    ) {
        $this->contextSwitchRoute = $contextSwitchRoute;
    }

    /**
     * @Route(
     *     "/account/order/payment/{orderId}",
     *     name="frontend.account.edit-order.change-payment-method",
     *     methods={"POST"}
     *     )
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
