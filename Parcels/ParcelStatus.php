<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Групи статусів посилки Meest для бейджів і фільтра замовлень.
 * Лише явні коди: діапазон 202–808 з довідки (міжнародні EM*) перекрив би 310–317, 606 і 707.
 */
final class ParcelStatus
{
    public const array GROUPS = [
        'created' => [2, 310, 311, 312, 313, 314, 315, 316, 317],
        'cancelled' => [3, 5700],
        'in_transit' => [11, 12, 101, 606, 1100, 1315, 1522, 2225, 2226, 2230, 2432, 2533, 8081],
        'arrived' => [1213, 1214, 1215, 1216],
        'storage' => [1220],
        'problem' => [1601, 1621, 1623, 1624, 1625, 1626, 1627, 1628, 1629, 1630, 2331, 5051],
        'returning' => [109, 707, 1521, 1620],
        'received' => [1622],
        'returned' => [1825],
    ];

    /** З посилкою більше нічого не станеться: трекінг її не опитує. */
    public const array FINAL = ['received', 'returned', 'cancelled'];

    /** Групи фільтра списку замовлень; `none` — замовлення без посилки. */
    public const array FILTER_GROUPS = ['created', 'in_transit', 'arrived', 'storage', 'problem', 'returning', 'received', 'returned', 'cancelled', 'none'];

    public static function group(int $code): ?string
    {
        foreach (self::GROUPS as $group => $codes) {
            if (in_array($code, $codes, true)) {
                return $group;
            }
        }

        return null;
    }

    /** Невідомий код групу не змінює: нову подію записує той, хто викликав. */
    public static function next(?string $current, int $code): ?string
    {
        return self::group($code) ?? $current;
    }
}
