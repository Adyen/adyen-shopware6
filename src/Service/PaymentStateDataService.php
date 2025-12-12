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

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Shopware\Entity\PaymentStateData\PaymentStateDataEntity;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class PaymentStateDataService
{
    /**
     * @var EntityRepository
     */
    protected EntityRepository $paymentStateDataRepository;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;


    /**
     * PaymentStateDataService constructor.
     * @param EntityRepository $paymentStateDataRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $paymentStateDataRepository,
        LoggerInterface $logger
    ) {
        $this->paymentStateDataRepository = $paymentStateDataRepository;
        $this->logger = $logger;
    }

    /**
     * @param string $contextToken
     * @param string $stateData
     * @param array $additionalData
     * @throws AdyenException
     */
    public function insertPaymentStateData(string $contextToken, string $stateData, array $additionalData = []): void
    {
        if (empty($contextToken) || empty($stateData)) {
            $message = 'No context token or state.data found, unable to save payment state.data';
            $this->logger->error($message);
            throw new AdyenException($message);
        }

        $stateDataArray = json_decode($stateData, true);

        //Set additional data to persist along with the state.data
        $stateDataArray['additionalData'] = $additionalData;

        $fields['token'] = $contextToken;
        $fields['statedata'] = json_encode($stateDataArray);

        $this->paymentStateDataRepository->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    /**
     * @param string $contextToken
     * @return PaymentStateDataEntity|null
     */
    public function getPaymentStateDataFromContextToken(string $contextToken): ?PaymentStateDataEntity
    {
        return $this->paymentStateDataRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('token', $contextToken)),
            Context::createDefaultContext()
        )->first();
    }

    public function getPaymentStateDataFromId(string $stateDataId): ?PaymentStateDataEntity
    {
        return $this->paymentStateDataRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('id', $stateDataId)),
            Context::createDefaultContext()
        )->first();
    }

    public function updateStateDataContextToken(PaymentStateDataEntity $stateData, $newToken): void
    {
        $this->paymentStateDataRepository->update([
            [
                'id' => $stateData->getId(),
                'token' => $newToken
            ]
        ], Context::createDefaultContext());
    }

    /**
     * @param PaymentStateDataEntity $stateData
     */
    public function deletePaymentStateData(PaymentStateDataEntity $stateData): void
    {
        $this->paymentStateDataRepository->delete(
            [
                ['id' => $stateData->getId()],
            ],
            Context::createDefaultContext()
        );
    }

    public function deletePaymentStateDataFromId(string $stateDataId, SalesChannelContext $context): void
    {
        $stateData = $this->getPaymentStateDataFromId($stateDataId);

        if (empty($stateData)) {
            return;
        }

        if ($context->getToken() !== $stateData->getToken()) {
            throw new UnauthorizedHttpException('Invalid context token.');
        }

        $this->deletePaymentStateData($stateData);
    }

    public function fetchRedeemedGiftCardsFromContextToken(string $contextToken):
    \Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('token', $contextToken));
        $criteria->addSorting(new FieldSorting('createdAt')); // Sorting by 'created_at' in ascending order

        return $this->paymentStateDataRepository->search(
            $criteria,
            Context::createDefaultContext()
        );
    }

    /**
     * Get the total gift card discount and balance.
     *
     * @param mixed $salesChannelContext The sales channel context.
     * @param int|mixed $remainingOrderAmount The remaining order amount.
     * @return array Array containing 'giftcardDiscount' and 'giftcardBalance'.
    */
    public function getGiftcardTotalDiscountAndBalance($salesChannelContext, $remainingOrderAmount): array
    {
        $fetchedRedeemedGiftcards = $this->fetchRedeemedGiftCardsFromContextToken($salesChannelContext->getToken());
        $totalGiftcardBalance = 0;
        foreach ($fetchedRedeemedGiftcards->getElements() as $fetchedRedeemedGiftcard) {
            $stateData = json_decode($fetchedRedeemedGiftcard->getStateData(), true);
            if (isset($stateData['paymentMethod']['type']) ||
                isset($stateData['paymentMethod']['brand']) ||
                $stateData['paymentMethod']['type'] === 'giftcard') {
                $totalGiftcardBalance += $stateData['giftcard']['value'];
            }
        }

        if ($totalGiftcardBalance > 0) {
            $totalDiscount = min($totalGiftcardBalance, $remainingOrderAmount);
        } else {
            $totalDiscount = 0;
        }
        return [
            'giftcardDiscount' => $totalDiscount,
            'giftcardBalance' => $totalGiftcardBalance
        ];
    }

    public function countStoredStateData($salesChannelContext): int
    {
        $stateData = $this->fetchRedeemedGiftCardsFromContextToken(
            $salesChannelContext->getToken()
        );
        return $stateData->getTotal();
    }

    public function getStoredStateData($salesChannelContext, string $transactionId): ?array
    {
        // Check for state.data in db using the context token
        $storedStateData = null;
        $stateDataEntity = $this->getPaymentStateDataFromContextToken(
            $salesChannelContext->getToken()
        );
        if ($stateDataEntity) {
            $storedStateData = json_decode($stateDataEntity->getStateData(), true);
            $storedStateData['id'] = $stateDataEntity->getId();
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AsyncPaymentProcessException(
                $transactionId,
                'Invalid payment state data.'
            );
        }
        return $storedStateData;
    }
}
