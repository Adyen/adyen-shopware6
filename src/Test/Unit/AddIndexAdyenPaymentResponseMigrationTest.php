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
    public function testUpdateCreatesIndexWhenNoneExists(): void
    {
        $connection = $this->getSimpleMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->callback(function (string $sql) {
                return str_contains($sql, 'CREATE INDEX `idx.adyen_payment_response.order_transaction_id`')
                    && str_contains($sql, '(`order_transaction_id`, `created_at`)');
            }));

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);
    }

    public function testUpdateSkipsWhenIndexOnOrderTransactionIdAlreadyExists(): void
    {
        $connection = $this->getSimpleMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1');
        $connection->expects($this->never())->method('executeStatement');

        (new Migration1793272392AddIndexAdyenPaymentResponse())->update($connection);
    }
}
