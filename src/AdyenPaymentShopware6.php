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
use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\PaymentMethods\PaymentMethodInterface;
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
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->addPaymentMethod(new $paymentMethod(), $installContext->getContext());
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(true, $activateContext->getContext(), new $paymentMethod());
        }
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), new $paymentMethod());
        }
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        //Deactivating payment methods
        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $uninstallContext->getContext(), new $paymentMethod());
        }

        //Exit here if the user prefers to keep the plugin's data
        if ($uninstallContext->keepUserData()) {
            return;
        }

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
    }

    public function update(UpdateContext $updateContext): void
    {
        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.2.0', '<')) {
            $this->updateTo120($updateContext);
        }
    }

    private function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        // Payment method exists already, no need to continue here
        if ($paymentMethodExists) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

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

    private function getPaymentMethodId(string $paymentMethodHandler): ?string
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        // Fetch ID for update

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
        PaymentMethodInterface $paymentMethod
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

    private function updateTo120(UpdateContext $updateContext): void
    {
        //Version 1.2.0 introduces storedPaymentMethod
        $this->addPaymentMethod(
            new \Adyen\Shopware\PaymentMethods\OneClickPaymentMethod,
            $updateContext->getContext()
        );
        $this->setPaymentMethodIsActive(
            true,
            $updateContext->getContext(),
            new \Adyen\Shopware\PaymentMethods\OneClickPaymentMethod
        );
    }
}

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
