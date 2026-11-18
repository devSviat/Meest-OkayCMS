<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries\Rows;

/** Ліміти точки з `branchLimits`: `0` у відповіді означає «без обмеження» і зберігається як NULL. */
final class BranchLimits
{
    /**
     * @param array<string, mixed> $limits
     * @return array<string, float|int|null>
     */
    public static function fromApi(array $limits): array
    {
        $gabarites = is_array($limits['gabaritesMax'] ?? null) ? $limits['gabaritesMax'] : [];

        return [
            'weight_place_max' => self::decimal($limits['weightPlaceMax'] ?? null, 999999.99),
            'weight_total_max' => self::decimal($limits['weightTotalMax'] ?? null, 999999.99),
            'volume_place_max' => self::decimal($limits['volumePlaceMax'] ?? null, 999999.9999),
            'insurance_max' => self::decimal($limits['insuranceTotalMax'] ?? null, 9999999999.99),
            'length_max' => self::integer($gabarites['length'] ?? null),
            'width_max' => self::integer($gabarites['width'] ?? null),
            'height_max' => self::integer($gabarites['height'] ?? null),
            'phone_required' => (int)filter_var($limits['receiverCellPhoneRequired'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sending_only' => (int)filter_var($limits['sendingOnly'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'receiving_only' => (int)filter_var($limits['receivingOnly'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Поза діапазоном колонки — сміття: невідомий ліміт, а не відхилений через нього весь пакет. */
    private static function decimal(mixed $value, float $max): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;

        return $number > 0 && $number <= $max ? $number : null;
    }

    private static function integer(mixed $value): ?int
    {
        $number = self::decimal($value, 2147483647.0);

        return $number === null ? null : (int)round($number);
    }
}
