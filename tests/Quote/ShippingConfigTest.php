<?php

namespace Modules\Sviat\Meest\Quote;

use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ShippingConfigTest extends TestCase
{
    public function testOwnerDefaultsWhenNothingStored(): void
    {
        $config = ShippingConfig::fromSettings(static fn(string $key): mixed => null);

        self::assertTrue($config->receiverPay);
        self::assertSame('cash', $config->payType);
        self::assertSame([30, 20, 15], [$config->lengthCm, $config->widthCm, $config->heightCm]);
        self::assertSame(1.0, $config->defaultWeightKg);
        self::assertTrue($config->declareTotal);
        self::assertSame(0.0, $config->declaredValue);
        self::assertSame('{order}', $config->notation);
        self::assertSame('16:00', $config->cutoff);
    }

    public function testCutoffNormalisedOrDefault(): void
    {
        $cutoff = static fn(string $raw): string => ShippingConfig::fromSettings(static fn(string $key): mixed => $key === ShippingConfig::CUTOFF ? $raw : null)->cutoff;

        self::assertSame('09:30', $cutoff('9:30'));
        self::assertSame('18:00', $cutoff('18:00'));
        self::assertSame('16:00', $cutoff('25:00'));
        self::assertSame('16:00', $cutoff('soon'));
    }

    public function testStoredZeroMeansSenderPays(): void
    {
        $stored = [ShippingConfig::RECEIVER_PAY => '0', ShippingConfig::DECLARE_TOTAL => '0'];
        $config = ShippingConfig::fromSettings(static fn(string $key): mixed => $stored[$key] ?? null);

        self::assertFalse($config->receiverPay);
        self::assertFalse($config->declareTotal);
    }

    public function testUnknownPayTypeFallsBackToCash(): void
    {
        $config = ShippingConfig::fromSettings(static fn(string $key): mixed => $key === ShippingConfig::PAY_TYPE ? 'barter' : null);

        self::assertSame('cash', $config->payType);
    }

    public function testNonPositiveValuesFallBackToDefaults(): void
    {
        $stored = [ShippingConfig::DEFAULT_WEIGHT => '-3', ShippingConfig::BOX_LENGTH => '0'];
        $config = ShippingConfig::fromSettings(static fn(string $key): mixed => $stored[$key] ?? null);

        self::assertSame(1.0, $config->defaultWeightKg);
        self::assertSame(30, $config->lengthCm);
    }

    public function testVolumeInCubicMetres(): void
    {
        $config = ShippingConfig::fromSettings(static fn(string $key): mixed => null);

        self::assertEqualsWithDelta(0.009, $config->volumeM3(), 1e-9);
    }
}
