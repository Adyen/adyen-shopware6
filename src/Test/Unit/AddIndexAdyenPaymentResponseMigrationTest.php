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

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Migration\Migration1793272392AddIndexAdyenPaymentResponse;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Doctrine\DBAL\Connection;

class AddIndexAdyenPaymentResponseMigrationTest extends AdyenTestCase
{
    private const ORDER_TRANSACTION_INDEX = 'idx.adyen_payment_response.order_transaction_id';
    private const PSPREFERENCE_INDEX = 'ADYEN_PAYMENT_RESPONSE_PSPREFERENCE';
    private const PSPREFERENCE_UNIQUE_INDEX = 'UQ_ADYEN_PAYMENT_RESPONSE_PSPREFERENCE';

    public function testUpdateCreatesIndexAndDropsRedundantPspreferenceIndex(): void
    {
        $executed = [];
        $connection = $this->createConnectionMock(
            [
                self::ORDER_TRANSACTION_INDEX => false,
                self::PSPREFERENCE_INDEX => true,
                self::PSPREFERENCE_UNIQUE_INDEX => true,
            ],
            $executed
        );

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);

        $this->assertCount(2, $executed);
        $this->assertStringContainsString('CREATE INDEX `' . self::ORDER_TRANSACTION_INDEX . '`', $executed[0]);
        $this->assertStringContainsString(
            'ON `adyen_payment_response` (`order_transaction_id`, `created_at`)',
            $executed[0]
        );
        $this->assertStringContainsString('ALGORITHM=INPLACE LOCK=NONE', $executed[0]);
        $this->assertStringContainsString(
            'DROP INDEX `' . self::PSPREFERENCE_INDEX . '` ON `adyen_payment_response`',
            $executed[1]
        );
    }

    public function testUpdateOnlyCreatesIndexWhenPspreferenceIndexIsAlreadyDropped(): void
    {
        $executed = [];
        $connection = $this->createConnectionMock(
            [self::ORDER_TRANSACTION_INDEX => false, self::PSPREFERENCE_INDEX => false],
            $executed
        );

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);

        $this->assertCount(1, $executed);
        $this->assertStringContainsString('CREATE INDEX `' . self::ORDER_TRANSACTION_INDEX . '`', $executed[0]);
    }

    public function testUpdateOnlyDropsPspreferenceIndexWhenIndexAlreadyExists(): void
    {
        $executed = [];
        $connection = $this->createConnectionMock(
            [
                self::ORDER_TRANSACTION_INDEX => true,
                self::PSPREFERENCE_INDEX => true,
                self::PSPREFERENCE_UNIQUE_INDEX => true,
            ],
            $executed
        );

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);

        $this->assertCount(1, $executed);
        $this->assertStringContainsString('DROP INDEX `' . self::PSPREFERENCE_INDEX . '`', $executed[0]);
    }

    public function testUpdateDoesNothingWhenIndexesAreAlreadyInPlace(): void
    {
        $executed = [];
        $connection = $this->createConnectionMock(
            [self::ORDER_TRANSACTION_INDEX => true, self::PSPREFERENCE_INDEX => false],
            $executed
        );

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);

        $this->assertSame([], $executed);
    }

    public function testUpdateKeepsPspreferenceIndexWhenUniqueIndexIsMissing(): void
    {
        $executed = [];
        $connection = $this->createConnectionMock(
            [
                self::ORDER_TRANSACTION_INDEX => true,
                self::PSPREFERENCE_INDEX => true,
                self::PSPREFERENCE_UNIQUE_INDEX => false,
            ],
            $executed
        );

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);

        $this->assertSame([], $executed);
    }

    public function testUpdateDestructiveDoesNothing(): void
    {
        $connection = $this->getSimpleMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');
        $connection->expects($this->never())->method('executeStatement');

        (new Migration1793272392AddIndexAdyenPaymentResponse())->updateDestructive($connection);
    }

    /**
     * Mocks the information_schema probe for indexes of adyen_payment_response and records executed statements.
     * Probing an index that is not listed in $existingIndexes fails the test.
     *
     * @param array<string, bool> $existingIndexes index name => exists
     * @param string[] $executed
     */
    private function createConnectionMock(array $existingIndexes, array &$executed): Connection
    {
        $connection = $this->getSimpleMock(Connection::class);
        $connection->method('fetchOne')
            ->with(
                $this->callback(function (string $sql) {
                    return str_contains($sql, 'FROM information_schema.STATISTICS')
                        && str_contains($sql, 'TABLE_SCHEMA = DATABASE()')
                        && str_contains($sql, "TABLE_NAME = 'adyen_payment_response'")
                        && str_contains($sql, 'INDEX_NAME = :indexName');
                }),
                $this->callback(function (array $params) use ($existingIndexes) {
                    return array_key_exists($params['indexName'] ?? '', $existingIndexes);
                })
            )
            ->willReturnCallback(function (string $sql, array $params) use ($existingIndexes) {
                return $existingIndexes[$params['indexName']] ? '1' : '0';
            });
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executed) {
                $executed[] = $sql;

                return 0;
            });

        return $connection;
    }
}
