<?php

namespace Okay\Modules\Sviat\Meest\Quote;

use Okay\Modules\Sviat\Meest\Cart\CartMetrics;

/**
 * Посилка, для якої рахується ціна: чого не кажуть товари, те доповнюють типові значення
 * з налаштувань, а габарити завжди беруться з типової коробки.
 */
final readonly class Parcel
{
    public function __construct(
        public float $weightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public float $volumeM3,
    ) {
    }

    public static function fromMetrics(CartMetrics $cart, ShippingConfig $config): self
    {
        $kilograms = $cart->knownWeight > 0 ? $cart->knownWeight : $config->defaultWeightKg;

        return new self(
            max(0.001, round($kilograms, 3)),
            $config->lengthCm,
            $config->widthCm,
            $config->heightCm,
            round($config->volumeM3(), 6),
        );
    }

    /** Оголошена цінність, грн: сума кошика чи замовлення або фіксована сума з налаштувань. */
    public static function insurance(ShippingConfig $config, float $totalUah): float
    {
        return round($config->declareTotal ? $totalUah : $config->declaredValue, 2);
    }
}
