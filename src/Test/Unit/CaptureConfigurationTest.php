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

use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CaptureConfigurationTest extends TestCase
{
    public function testGlobalIsNotAnEffectiveMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CaptureConfiguration(CaptureMode::GLOBAL);
    }

    public function testParametersThatDoNotBelongToTheModeAreDiscarded(): void
    {
        $delayed = new CaptureConfiguration(CaptureMode::DELAYED, 3, 'state-id', 600);
        self::assertSame(3, $delayed->delayDays);
        self::assertNull($delayed->orderStateId);
        self::assertNull($delayed->retryIntervalSeconds);

        $onShipment = new CaptureConfiguration(CaptureMode::ON_SHIPMENT, 3, 'state-id', 600);
        self::assertNull($onShipment->delayDays);
        self::assertSame('state-id', $onShipment->orderStateId);
        self::assertSame(600, $onShipment->retryIntervalSeconds);

        $manual = new CaptureConfiguration(CaptureMode::MANUAL, 3, 'state-id', 600);
        self::assertNull($manual->delayDays);
        self::assertNull($manual->orderStateId);
        self::assertNull($manual->retryIntervalSeconds);
    }

    public function testCaptureDelayHours(): void
    {
        self::assertSame(0, (new CaptureConfiguration(CaptureMode::IMMEDIATE))->getCaptureDelayHours());
        self::assertSame(72, (new CaptureConfiguration(CaptureMode::DELAYED, 3))->getCaptureDelayHours());
        self::assertNull((new CaptureConfiguration(CaptureMode::DELAYED))->getCaptureDelayHours());
        self::assertNull((new CaptureConfiguration(CaptureMode::MANUAL))->getCaptureDelayHours());
        self::assertNull((new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'id', 60))->getCaptureDelayHours());
    }

    public function testManualCaptureAndCaptureOnShipmentFlags(): void
    {
        $onShipment = new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'id', 60);
        self::assertTrue($onShipment->isManualCapture());
        self::assertTrue($onShipment->isCaptureOnShipment());

        $manual = new CaptureConfiguration(CaptureMode::MANUAL);
        self::assertTrue($manual->isManualCapture());
        self::assertFalse($manual->isCaptureOnShipment());

        self::assertFalse(CaptureConfiguration::automatic()->isManualCapture());
        self::assertFalse(CaptureConfiguration::automatic()->isDeferredCapture());

        $delayed = new CaptureConfiguration(CaptureMode::DELAYED, 2);
        self::assertFalse($delayed->isManualCapture());
        self::assertTrue($delayed->isDeferredCapture());
        self::assertFalse($delayed->isCaptureOnShipment());
        self::assertSame(CaptureMode::IMMEDIATE, CaptureConfiguration::automatic()->mode);
    }

    public function testSnapshotRoundTrip(): void
    {
        $configuration = new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-id', 43200);

        self::assertSame(
            [
                'mode' => 'on_shipment',
                'delayDays' => null,
                'orderStateId' => 'state-id',
                'retryIntervalSeconds' => 43200,
            ],
            $configuration->toArray()
        );
        self::assertEquals($configuration, CaptureConfiguration::fromArray($configuration->toArray()));
    }

    #[DataProvider('invalidSnapshots')]
    public function testFromArrayRejectsInvalidSnapshots(mixed $data): void
    {
        self::assertNull(CaptureConfiguration::fromArray($data));
    }

    public static function invalidSnapshots(): array
    {
        return [
            'null' => [null],
            'string' => ['manual'],
            'no mode' => [['delayDays' => 3]],
            'unknown mode' => [['mode' => 'auto']],
            'global mode' => [['mode' => 'global']],
        ];
    }

    public function testFromArrayIgnoresInvalidParameters(): void
    {
        $configuration = CaptureConfiguration::fromArray(['mode' => 'delayed', 'delayDays' => '3']);

        self::assertNotNull($configuration);
        self::assertSame(CaptureMode::DELAYED, $configuration->mode);
        self::assertNull($configuration->delayDays);
    }
}
