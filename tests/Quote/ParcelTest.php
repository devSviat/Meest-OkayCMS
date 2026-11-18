<?php

namespace Modules\Sviat\Meest\Quote;

use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Quote\Parcel;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ParcelTest extends TestCase
{
    public function testUnknownWeightUsesDefault(): void
    {
        $parcel = Parcel::fromMetrics(new CartMetrics(0.0, true, 500.0), ShippingConfig::fromSettings(static fn(string $k): mixed => null));

        self::assertSame(1.0, $parcel->weightKg);
        self::assertSame([30, 20, 15], [$parcel->lengthCm, $parcel->widthCm, $parcel->heightCm]);
        self::assertSame(0.009, $parcel->volumeM3);
    }

    public function testKnownWeightAndBoxFromSettings(): void
    {
        $settings = [ShippingConfig::BOX_LENGTH => '40', ShippingConfig::BOX_WIDTH => '25.2', ShippingConfig::BOX_HEIGHT => '10', ShippingConfig::DEFAULT_WEIGHT => '2'];
        $parcel = Parcel::fromMetrics(new CartMetrics(0.3505, false, 500.0), ShippingConfig::fromSettings(static fn(string $k): mixed => $settings[$k] ?? null));

        self::assertSame(0.351, $parcel->weightKg, 'grams precision, in kilograms');
        self::assertSame([40, 26, 10], [$parcel->lengthCm, $parcel->widthCm, $parcel->heightCm]);
        self::assertSame(0.0104, $parcel->volumeM3);
    }

    public function testInsuranceIsCartTotalOrFixedValue(): void
    {
        $total = ShippingConfig::fromSettings(static fn(string $k): mixed => null);
        $fixed = ShippingConfig::fromSettings(static fn(string $k): mixed => [ShippingConfig::DECLARE_TOTAL => '0', ShippingConfig::DECLARED_VALUE => '300'][$k] ?? null);

        self::assertSame(1500.0, Parcel::insurance($total, 1500.0));
        self::assertSame(1999.99, Parcel::insurance($total, 1999.991));
        self::assertSame(300.0, Parcel::insurance($fixed, 1500.0));
    }
}
