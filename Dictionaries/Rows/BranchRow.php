<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries\Rows;

/** Запис `branchSearch` як рядок таблиці, або `null` для пункту, якого не має бути в кошику. */
final class BranchRow
{
    /**
     * Лише перелічені колонки: ПІБ, телефони й email регіональних менеджерів, способи оплати, послуги
     * й навантаження відділення із відповіді не переносяться.
     *
     * @param array<string, mixed> $b запис `branchSearch`
     * @param string $regionId область, яку обходили
     */
    public static function fromApi(array $b, string $regionId): ?array
    {
        if (filter_var($b['isBranchClosed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $kind = BranchKind::classify(self::text($b['branchType'] ?? null, 16), $b['branchTypeAPP'] ?? null);
        $id = self::uuid($b['branchID'] ?? null);
        $cityId = self::uuid($b['cityID'] ?? null);
        if ($kind === null || $id === null || $cityId === null) {
            return null;
        }
        $limits = is_array($b['branchLimits'] ?? null) ? $b['branchLimits'] : [];
        $restricted = filter_var($b['InboundRestriction'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return RowHash::with([
            'id' => $id,
            'region_id' => strtolower(trim($regionId)),
            'city_id' => $cityId,
            'number' => self::text($b['branchNumber'] ?? null, 16) ?? '',
            'kind' => $kind->value,
            'type_code' => self::text($b['branchType'] ?? null, 16) ?? '',
            'name_ua' => self::text($b['ShortName'] ?? null, 255) ?? self::text($b['FullName'] ?? null, 255) ?? '',
            'address_ua' => self::address($b),
            'zip' => self::text($b['zipCode'] ?? null, 10),
            'lat' => self::coordinate($b['latitude'] ?? null),
            'lng' => self::coordinate($b['longitude'] ?? null),
            'schedule_json' => self::schedule($b['branchWorkTime'] ?? null),
            ...BranchLimits::fromApi($limits),
            'inbound_from' => $restricted ? self::date($b['InboundRestrictionStartDate'] ?? null) : null,
            'inbound_to' => $restricted ? self::date($b['InboundRestrictionEndDate'] ?? null) : null,
            'overloaded' => (int)filter_var($b['overloaded'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /** `branchType` пункту, який відкинуто через невідомий тип, для звіту завантаження; інакше `null`. */
    public static function unknownType(array $b): ?string
    {
        $type = self::text($b['branchType'] ?? null, 64);
        if ($type === null || $type === 'ТОЧКА') {
            return null;
        }

        return BranchKind::classify($type, $b['branchTypeAPP'] ?? null) === null ? $type : null;
    }

    private static function address(array $b): string
    {
        $descr = $b['addressDescr'] ?? null;
        $street = self::text(is_array($descr) ? ($descr['descrUA'] ?? null) : null, 255);
        $building = self::text($b['building'] ?? null, 64);

        return mb_substr(trim($street . ' ' . $building), 0, 255);
    }

    private static function schedule(mixed $days): ?string
    {
        if (!is_array($days)) {
            return null;
        }
        $rows = [];
        foreach ($days as $day) {
            if (!is_array($day) || self::text($day['day'] ?? null, 16) === null) {
                continue;
            }
            $rows[] = [
                'day' => self::text($day['day'], 16),
                'from' => self::text($day['timeFrom'] ?? null, 8),
                'to' => self::text($day['timeTo'] ?? null, 8),
                'break_from' => self::text($day['LunchBreakFrom'] ?? null, 8),
                'break_to' => self::text($day['LunchBreakTo'] ?? null, 8),
            ];
        }

        return $rows === [] ? null : json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Дата без часу: Meest віддає ISO-мітку з нульовим часом, а сірість рахується по днях. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }

    private static function coordinate(mixed $value): ?float
    {
        return is_numeric($value) && abs((float)$value) <= 999.9999999 && (float)$value !== 0.0 ? (float)$value : null;
    }

    private static function uuid(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return $value === '' ? null : $value;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string)$value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
