<?php

namespace Okay\Modules\Sviat\Meest\Orders;

use Okay\Modules\Sviat\Meest\Parcels\Barcode;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;

/** Частини фільтра списку замовлень, які хаб Sviat/DeliveryFilter делегує цьому модулю. */
final class OrdersListFilter
{
    public const string NONE = 'none';

    /** @return ?string група з ParcelStatus::FILTER_GROUPS або null */
    public static function group(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, ParcelStatus::FILTER_GROUPS, true) ? $raw : null;
    }

    /** @param int $moduleId id цього модуля: `none` — замовлення з його доставкою без посилки */
    public static function byStatus(array $filter, mixed $group, int $moduleId): array
    {
        $group = self::group($group);
        if ($group === self::NONE) {
            $filter['meest_without_parcel'] = $moduleId;
        } elseif ($group !== null) {
            // Підзапит за індексованим status_group (OrdersEntityFilters), а не id кожного замовлення групи.
            $filter['meest_status_groups'] = [$group];
        }

        return $filter;
    }

    /**
     * Штрихкод, якщо текст пошуку ним є. Barcode::parse пропускає й латинське слово з шести літер, а пошук
     * за ним сканує таблицю посилок (дефіси ігноруються): штрихкод Meest завжди має цифри.
     */
    public static function barcode(mixed $keyword): ?string
    {
        $barcode = is_string($keyword) ? Barcode::parse($keyword) : null;

        return $barcode !== null && preg_match('/\d/', $barcode) === 1 ? $barcode : null;
    }
}
