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
use Adyen\Shopware\PaymentMethods;
use Adyen\Shopware\Service\ConfigurationService;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Doctrine\DBAL\Connection;

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

        if (\version_compare($currentVersion, '3.1.0', '<')) {
            $this->updateTo310($updateContext);
        }

        if (\version_compare($currentVersion, '3.2.0', '<')) {
            $this->updateTo320($updateContext);
        }
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

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPluginId(string $paymentMethodId, string $pluginId, Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentMethodData = [
            'id' => $paymentMethodId,
            'pluginId' => $pluginId,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function getPaymentMethodId(string $paymentMethodHandler): ?string
    {
        /** @var EntityRepositoryInterface $paymentRepository */
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
        /** @var EntityRepositoryInterface $paymentRepository */
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

    private function removePluginData()
    {
        //Search for config keys that contain the bundle's name
        /** @var EntityRepositoryInterface $systemConfigRepository */
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
            $connection->executeUpdate(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
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

    private function updateTo310(UpdateContext $updateContext): void
    {
        //Version 3.1.0 introduces gift cards
        foreach ([
                     new PaymentMethods\GivexGiftCardPaymentMethod,
                     new PaymentMethods\WebshopGiftCardPaymentMethod,
                     new PaymentMethods\KadowereldGiftCardPaymentMethod,
                     new PaymentMethods\TCSTestGiftCardPaymentMethod,
                     new PaymentMethods\AlbelliGiftCardPaymentMethod,
                     new PaymentMethods\BijenkorfGiftCardPaymentMethod,
                     new PaymentMethods\VVVGiftCardPaymentMethod,
                     new PaymentMethods\GallGallGiftCardPaymentMethod,
                     new PaymentMethods\HunkemollerLingerieGiftCardPaymentMethod,
                     new PaymentMethods\BeautyGiftCardPaymentMethod,
                     new PaymentMethods\SVSGiftCardPaymentMethod,
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

    private function updateTo320(UpdateContext $updateContext): void
    {
        foreach ([
                     new PaymentMethods\FashionChequeGiftCardPaymentMethod(),
                     new PaymentMethods\DeCadeaukaartGiftCardPaymentMethod(),
                     new PaymentMethods\GenericGiftCardPaymentMethod()
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

        // De-activate Savvy payment method. SetPaymentMethodIsActive() is not used since this requires a method and
        // in our case, the savvy method no longer exists.
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentMethodId = $this->getPaymentMethodId(
            'Adyen\Shopware\Handlers\SavvyGiftCardPaymentMethodHandler'
        );

        // If savvy payment method is not found, return
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethodData = [
            'id' => $paymentMethodId,
            'active' => false,
        ];

        $paymentRepository->update([$paymentMethodData], $updateContext->getContext());
    }
}

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
