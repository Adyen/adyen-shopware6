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

use Adyen\Shopware\Models\Capture\CaptureMode;
use PHPUnit\Framework\TestCase;

class CaptureModeTest extends TestCase
{
    public function testIsManualCaptureOnlyForModesThatOnlyAuthorise(): void
    {
        self::assertFalse(CaptureMode::IMMEDIATE->isManualCapture());
        self::assertFalse(CaptureMode::DELAYED->isManualCapture());
        self::assertTrue(CaptureMode::MANUAL->isManualCapture());
        self::assertTrue(CaptureMode::ON_SHIPMENT->isManualCapture());
    }

    public function testIsDeferredCaptureForEveryModeExceptImmediate(): void
    {
        self::assertFalse(CaptureMode::IMMEDIATE->isDeferredCapture());
        self::assertTrue(CaptureMode::DELAYED->isDeferredCapture());
        self::assertTrue(CaptureMode::MANUAL->isDeferredCapture());
        self::assertTrue(CaptureMode::ON_SHIPMENT->isDeferredCapture());
    }

    public function testTryFromConfigReturnsTheModeForKnownValues(): void
    {
        self::assertSame(CaptureMode::GLOBAL, CaptureMode::tryFromConfig('global'));
        self::assertSame(CaptureMode::IMMEDIATE, CaptureMode::tryFromConfig('immediate'));
        self::assertSame(CaptureMode::DELAYED, CaptureMode::tryFromConfig('delayed'));
        self::assertSame(CaptureMode::MANUAL, CaptureMode::tryFromConfig('manual'));
        self::assertSame(CaptureMode::ON_SHIPMENT, CaptureMode::tryFromConfig('on_shipment'));
    }

    public function testTryFromConfigReturnsNullForEmptyOrUnknownValues(): void
    {
        self::assertNull(CaptureMode::tryFromConfig(null));
        self::assertNull(CaptureMode::tryFromConfig(''));
        self::assertNull(CaptureMode::tryFromConfig('auto'));
        self::assertNull(CaptureMode::tryFromConfig(true));
        self::assertNull(CaptureMode::tryFromConfig(['manual']));
    }
}
