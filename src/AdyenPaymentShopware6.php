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
// phpcs:disable PSR1.Files.SideEffects

namespace Adyen\Shopware;

use Adyen\Shopware\Entity\Notification\NotificationEntityDefinition;
use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntityDefinition;
use Adyen\Shopware\Entity\PaymentStateData\PaymentStateDataEntityDefinition;
use Adyen\Shopware\Service\ConfigurationService;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Doctrine\DBAL\Connection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class AdyenPaymentShopware6 extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->addPaymentMethod(new $paymentMethod(), $installContext->getContext());
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(true, $activateContext->getContext(), new $paymentMethod());
        }
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), new $paymentMethod());
        }
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        //Deactivating payment methods
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $uninstallContext->getContext(), new $paymentMethod());
        }

        //Exit here if the user prefers to keep the plugin's data
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->removePluginData();
    }

    public function update(UpdateContext $updateContext): void
    {
        $currentVersion = $updateContext->getCurrentPluginVersion();

        if (\version_compare($currentVersion, '1.2.0', '<')) {
            $this->updateTo120($updateContext);
        }

        if (\version_compare($currentVersion, '1.4.0', '<')) {
            $this->updateTo140($updateContext);
        }

        if (\version_compare($currentVersion, '1.6.0', '<')) {
            $this->updateTo160($updateContext);
        }

        if (\version_compare($currentVersion, '2.0.0', '<')) {
            $this->updateTo200($updateContext);
        }

        if (\version_compare($currentVersion, '3.0.0', '<')) {
            $this->updateTo300($updateContext);
        }

        if (\version_compare($currentVersion, '3.2.0', '<')) {
            $this->updateTo320($updateContext);
        }

        if (\version_compare($currentVersion, '3.5.0', '<')) {
            $this->updateTo350($updateContext);
        }

        if (\version_compare($currentVersion, '3.7.0', '<')) {
            $this->updateTo370($updateContext);
        }

        if (\version_compare($currentVersion, '3.10.0', '<')) {
            $this->updateTo3100($updateContext);
        }

        if (\version_compare($currentVersion, '3.15.0', '<')) {
            $this->updateTo3150($updateContext);
            $this->updateTo400($updateContext);
        }

//        if (\version_compare($currentVersion, '4.0.0', '<')) {
//            $this->updateTo400($updateContext);
//        }
    }

    private function addPaymentMethod(PaymentMethods\PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        // Payment method exists already, set the pluginId
        if ($paymentMethodId) {
            $this->setPluginId($paymentMethodId, $pluginId, $context);
            return;
        }

        $paymentData = [
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'description' => $paymentMethod->getDescription(),
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true
        ];

        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPluginId(string $paymentMethodId, string $pluginId, Context $context): void
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentMethodData = [
            'id' => $paymentMethodId,
            'pluginId' => $pluginId,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function getPaymentMethodId(string $paymentMethodHandler): ?string
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter(
            'handlerIdentifier',
            $paymentMethodHandler
        ));

        $paymentIds = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }

    private function setPaymentMethodIsActive(
        bool $active,
        Context $context,
        PaymentMethods\PaymentMethodInterface $paymentMethod
    ): void {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethodData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function removePluginData(): void
    {
        //Search for config keys that contain the bundle's name
        /** @var EntityRepository $systemConfigRepository */
        $systemConfigRepository = $this->container->get('system_config.repository');
        $criteria = (new Criteria())
            ->addFilter(
                new ContainsFilter('configurationKey', ConfigurationService::BUNDLE_NAME . '.config')
            );
        $idSearchResult = $systemConfigRepository->searchIds($criteria, Context::createDefaultContext());

        //Formatting IDs array and deleting config keys
        $ids = \array_map(static function ($id) {
            return ['id' => $id];
        }, $idSearchResult->getIds());
        $systemConfigRepository->delete($ids, Context::createDefaultContext());

        //Dropping database tables
        $tables = [
            NotificationEntityDefinition::ENTITY_NAME,
            PaymentStateDataEntityDefinition::ENTITY_NAME,
            PaymentResponseEntityDefinition::ENTITY_NAME
        ];
        $connection = $this->container->get(Connection::class);
        foreach ($tables as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }

        $this->removeMigrations();
    }

    private function updateTo120(UpdateContext $updateContext): void
    {
        //Version 1.2.0 introduces storedPaymentMethod
        $this->addPaymentMethod(
            new PaymentMethods\OneClickPaymentMethod,
            $updateContext->getContext()
        );
        $this->setPaymentMethodIsActive(
            true,
            $updateContext->getContext(),
            new PaymentMethods\OneClickPaymentMethod
        );
    }

    private function updateTo140(UpdateContext $updateContext): void
    {
        //Version 1.4.0 introduces giropay
        $this->addPaymentMethod(
            new PaymentMethods\GiroPayPaymentMethod,
            $updateContext->getContext()
        );
        $this->setPaymentMethodIsActive(
            true,
            $updateContext->getContext(),
            new PaymentMethods\GiroPayPaymentMethod
        );
    }

    private function updateTo160(UpdateContext $updateContext): void
    {
        //Version 1.6.0 introduces applepay, paywithgoogle, dotpay and bancontact
        foreach ([
                     new PaymentMethods\ApplePayPaymentMethod,
                     new PaymentMethods\GooglePayPaymentMethod,
                     new PaymentMethods\DotpayPaymentMethod,
                     new PaymentMethods\BancontactCardPaymentMethod
                 ] as $method) {
            $this->addPaymentMethod(
                $method,
                $updateContext->getContext()
            );
            $this->setPaymentMethodIsActive(
                true,
                $updateContext->getContext(),
                $method
            );
        }
    }

    private function updateTo200(UpdateContext $updateContext): void
    {
        //Version 2.0.0 introduces amazonpay, blik
        foreach ([
                     new PaymentMethods\AmazonPayPaymentMethod,
                     new PaymentMethods\BlikPaymentMethod,
                 ] as $method) {
            $this->addPaymentMethod(
                $method,
                $updateContext->getContext()
            );
            $this->setPaymentMethodIsActive(
                true,
                $updateContext->getContext(),
                $method
            );
        }
    }

    private function updateTo300(UpdateContext $updateContext): void
    {
        //Version 3.0.0 introduces the following payment methods
        foreach ([
                     new PaymentMethods\AfterpayDefaultPaymentMethod,
                     new PaymentMethods\AlipayPaymentMethod,
                     new PaymentMethods\AlipayHkPaymentMethod,
                     new PaymentMethods\ClearpayPaymentMethod,
                     new PaymentMethods\EpsPaymentMethod,
                     new PaymentMethods\Facilypay3xPaymentMethod,
                     new PaymentMethods\Facilypay4xPaymentMethod,
                     new PaymentMethods\Facilypay6xPaymentMethod,
                     new PaymentMethods\Facilypay10xPaymentMethod,
                     new PaymentMethods\Facilypay12xPaymentMethod,
                     new PaymentMethods\PaysafecardPaymentMethod,
                     new PaymentMethods\RatepayPaymentMethod,
                     new PaymentMethods\RatepayDirectdebitPaymentMethod,
                     new PaymentMethods\SwishPaymentMethod,
                     new PaymentMethods\TrustlyPaymentMethod,
                     new PaymentMethods\TwintPaymentMethod,
                 ] as $method) {
            $this->addPaymentMethod(
                $method,
                $updateContext->getContext()
            );
            $this->setPaymentMethodIsActive(
                true,
                $updateContext->getContext(),
                $method
            );
        }
    }

    /**
     * It will also remove all links of Savvy payment_method in sales_channel_payment_method and recreate these links
     * for the new payment method
     *
     * @param UpdateContext $updateContext
     */
    private function updateTo320(UpdateContext $updateContext): void
    {
        $paymentMethodHandler = 'Adyen\Shopware\Handlers\SavvyGiftCardPaymentMethodHandler';
        $this->deactivateAndRemovePaymentMethod($updateContext, $paymentMethodHandler);
    }

    private function updateTo350(UpdateContext $updateContext): void
    {
        //Version 3.5.0 introduces Bancontact mobile
        foreach ([
                     new PaymentMethods\BancontactMobilePaymentMethod()
                 ] as $method) {
            $this->addPaymentMethod(
                $method,
                $updateContext->getContext()
            );
            $this->setPaymentMethodIsActive(
                true,
                $updateContext->getContext(),
                $method
            );
        }
    }

    private function updateTo370(UpdateContext $updateContext): void
    {
        /*
         * Version 3.7.0 introduces following payment methods.
         * MB Way, Multibanco, WeChat Pay, MobilePay, Vipps, Affirm & PayBright
         */
        foreach ([
                     new PaymentMethods\MbwayPaymentMethod(),
                     new PaymentMethods\MultibancoPaymentMethod(),
                     new PaymentMethods\WechatpayqrPaymentMethod(),
                     new PaymentMethods\WechatpaywebPaymentMethod(),
                     new PaymentMethods\MobilePayPaymentMethod(),
                     new PaymentMethods\VippsPaymentMethod(),
                     new PaymentMethods\AffirmPaymentMethod(),
                     new PaymentMethods\PayBrightPaymentMethod()
                 ] as $method) {
            $this->addPaymentMethod(
                $method,
                $updateContext->getContext()
            );
            $this->setPaymentMethodIsActive(
                true,
                $updateContext->getContext(),
                $method
            );
        }
    }

    private function updateTo3100(UpdateContext $updateContext): void
    {
        /*
         * Version 3.10.0 introduces following payment methods.
         * Open Banking / Pay by Bank
         */
        foreach ([
            new PaymentMethods\OpenBankingPaymentMethod(),
        ] as $method) {
            $this->addPaymentMethod(
                $method,
                $updateContext->getContext()
            );
            $this->setPaymentMethodIsActive(
                true,
                $updateContext->getContext(),
                $method
            );
        }
    }

    private function updateTo3150(UpdateContext $updateContext): void
    {
        //Version 3.15.0 introduces MultiGiftcards
        $this->addPaymentMethod(
            new PaymentMethods\GiftCardPaymentMethod(),
            $updateContext->getContext()
        );
        $this->setPaymentMethodIsActive(
            true,
            $updateContext->getContext(),
            new PaymentMethods\GiftcardPaymentMethod()
        );

        $deprecatedGiftcardMethods = [
            'Adyen\Shopware\Handlers\AlbelliGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\BeautyGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\BijenkorfGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\DeCadeaukaartGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\FashionChequeGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\GallGallGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\GenericGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\GivexGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\HunkemollerLingerieGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\KadowereldGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\SVSGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\TCSTestGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\VVVGiftCardPaymentMethodHandler',
            'Adyen\Shopware\Handlers\WebshopGiftCardPaymentMethodHandler'
        ];

        // Disable deprecated gift card payment methods
        foreach ($deprecatedGiftcardMethods as $deprecatedGiftcardMethod) {
            $description = '@deprecated DO NOT ACTIVATE, use GiftCard instead';
            $this->deactivateAndRemovePaymentMethod($updateContext, $deprecatedGiftcardMethod, $description);
        }
    }

    private function updateTo400(UpdateContext $updateContext): void
    {
        /*
         * Version 4.0.0 introduces following payment methods.
         * Billie
         */
        $this->addPaymentMethod(
            new PaymentMethods\BilliePaymentMethod(),
            $updateContext->getContext()
        );
        $this->setPaymentMethodIsActive(
            true,
            $updateContext->getContext(),
            new PaymentMethods\BilliePaymentMethod()
        );
    }

    /**
     * @param UpdateContext $updateContext
     * @param string $paymentMethodHandler
     * @param string|null $description
     * @return void
     */
    private function deactivateAndRemovePaymentMethod(
        UpdateContext $updateContext,
        string $paymentMethodHandler,
        string $description = null
    ): void {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        /** @var EntityRepository $salesChannelPaymentRepository */
        $salesChannelPaymentRepository = $this->container->get('sales_channel_payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId($paymentMethodHandler);
        // If payment method is not found, return
        if (!$paymentMethodId) {
            return;
        }
        $paymentMethodData = [
            'id' => $paymentMethodId,
            'active' => false
        ];

        // Update description as deprecation message
        if (isset($description)) {
            $paymentMethodData['description'] = $description;
        }

        // Set the payment method to inactive
        $paymentRepository->update([$paymentMethodData], $updateContext->getContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $paymentMethodId));
        $criteria->addAssociation('salesChannels');

        /** @var PaymentMethodEntity $paymentMethod */
        $paymentMethod = $paymentRepository->search($criteria, $updateContext->getContext())->first();
        $salesChannels = $paymentMethod->getSalesChannels();

        if (count($salesChannels) > 0) {
            /** @var SalesChannelEntity $savvySalesChannel */
            foreach ($paymentMethod->getSalesChannels() as $savvySalesChannel) {
                $salesChannelPaymentRepository->delete([
                    [
                        'salesChannelId' => $savvySalesChannel->getId(),
                        'paymentMethodId' => $paymentMethodId
                    ]
                ], $updateContext->getContext());
            }
        }
    }
}

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
