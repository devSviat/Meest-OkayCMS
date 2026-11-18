<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Картка посилки на сторінці замовлення: значення рядка, відформатовані для шаблону; слова — мовні ключі.
 * Чого немає в рядку, того немає і в картці (null чи порожній список): шаблон таке поле не друкує.
 */
final class ParcelCard
{
    private const string TRACKING_URL = 'https://t.meest-group.com/';

    /**
     * @param ?string $point пункт видачі зі знімка замовлення; ParcelView дає його, лише коли це пункт самої посилки
     * @return array{barcode: ?string, trackUrl: ?string, statusGroup: ?string, statusKey: ?string, event: ?array{name: string, place: ?string, at: ?string}, recipient: ?string, phone: ?string, point: ?string, deliveryPrice: ?string, payerKey: ?string, payTypeKey: ?string, plannedDate: ?string, declared: ?string, more: array<string, string>, history: list<array{name: string, place: ?string, at: string}>, created: ?string, checked: ?string}
     */
    public static function fromRow(object $row, ?string $point = null): array
    {
        $code = self::int($row->status_code ?? null);
        $group = is_string($row->status_group ?? null) && $row->status_group !== ''
            ? $row->status_group
            : ($code !== null ? ParcelStatus::group($code) : null);
        $details = ParcelDetails::decode($row->details ?? null);
        $history = ParcelHistory::decode($row->history ?? null);
        $barcode = self::text((string)($row->barcode ?? ''));
        $parsed = $barcode !== null ? Barcode::parse($barcode) : null;
        $receiverPay = $row->receiver_pay ?? null;
        $payType = $row->pay_type ?? null;

        return [
            'barcode' => $barcode,
            'trackUrl' => $parsed !== null ? self::TRACKING_URL . rawurlencode($parsed) : null,
            'statusGroup' => $group,
            'statusKey' => $group !== null && in_array($group, ParcelStatus::FILTER_GROUPS, true) ? 'sviat__meest__parcel_group_' . $group : null,
            'event' => self::event($row, $code, $history),
            'recipient' => self::text((string)($details['receiver_name'] ?? '')),
            'phone' => self::phone($details['receiver_phone'] ?? null),
            'point' => isset($details['branch_id']) ? self::text((string)$point) : null,
            'deliveryPrice' => self::money($row->delivery_price ?? null),
            // Прив'язана вручну посилка платника не має: картка його не вигадує.
            'payerKey' => $receiverPay === null || $receiverPay === '' ? null : 'sviat__meest__parcel_pays_' . ((bool)$receiverPay ? 'receiver' : 'sender'),
            'payTypeKey' => is_string($payType) && in_array($payType, ['cash', 'noncash'], true) ? 'sviat__meest__parcel_' . $payType : null,
            // Після вручення, повернення чи скасування планова дата вже нічого не каже.
            'plannedDate' => in_array($group, ParcelStatus::FINAL, true) ? null : self::date($details['delivery_date'] ?? null),
            'declared' => self::money($row->declared_value ?? $details['declared'] ?? null),
            'more' => self::more($row, $details),
            'history' => array_map(static fn(array $e): array => [
                'name' => $e['name'],
                'place' => self::text($e['place']),
                'at' => (string)self::dateTime($e['at']),
            ], array_reverse($history)),
            'created' => self::dateTime($row->created_at ?? null),
            'checked' => self::dateTime($row->checked_at ?? null),
        ];
    }

    /**
     * Поточна подія з рядка; місця колонки рядка не мають, воно є лише в тій самій події історії.
     *
     * @param list<array{code: int, name: string, place: string, at: string}> $history
     * @return ?array{name: string, place: ?string, at: ?string}
     */
    private static function event(object $row, ?int $code, array $history): ?array
    {
        $name = self::text((string)($row->event_name ?? ''));
        if ($name === null) {
            return null;
        }
        $place = null;
        foreach ($history as $entry) {
            if ($entry['code'] === $code && $entry['at'] === ($row->event_at ?? null)) {
                $place = self::text($entry['place']);
            }
        }

        return ['name' => $name, 'place' => $place, 'at' => self::dateTime($row->event_at ?? null)];
    }

    /**
     * @param array<string, string|int|float> $details
     * @return array<string, string>
     */
    private static function more(object $row, array $details): array
    {
        $sides = array_filter([$details['length'] ?? null, $details['width'] ?? null, $details['height'] ?? null], is_int(...));

        return array_filter([
            'weight' => isset($details['weight_kg']) ? self::number((float)$details['weight_kg']) : null,
            'sides' => count($sides) === 3 ? implode('×', $sides) : null,
            'volume' => isset($details['volume']) ? self::number((float)$details['volume']) : null,
            'description' => is_string($details['description'] ?? null) ? $details['description'] : null,
            'notation' => is_string($details['notation'] ?? null) ? $details['notation'] : null,
            'parcelNumber' => self::text((string)($row->parcel_number ?? '')),
        ], static fn(?string $value): bool => $value !== null);
    }

    /** MeestPhone зберігає номер як +380XXXXXXXXX; інший вигляд показується як є. */
    private static function phone(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return preg_match('/^\+380(\d{2})(\d{3})(\d{2})(\d{2})$/', $value, $m) ? "+380 {$m[1]} {$m[2]} {$m[3]} {$m[4]}" : $value;
    }

    private static function text(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** Без зайвих нулів: 1.25 кг, а не 1.250. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private static function money(mixed $value): ?string
    {
        return is_numeric($value) && (float)$value > 0 ? number_format((float)$value, 2, '.', '') : null;
    }

    private static function date(mixed $value): ?string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date ? $date->format('d.m.Y') : null;
    }

    private static function dateTime(mixed $value): ?string
    {
        $date = is_string($value) && $value !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value) : false;

        return $date ? $date->format('d.m.Y H:i') : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
