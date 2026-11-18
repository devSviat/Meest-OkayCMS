<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Знімок посилки для картки (колонка `details`), без нових запитів: з чернетки й відповіді `POST parcel` або з
 * елемента `parcelsList` при пошуку. Лише білий список ключів, текст обрізаний до лімітів форми, числа приведені;
 * те саме очищення стоїть і на читанні, тож зіпсований JSON дає порожній знімок.
 */
final class ParcelDetails
{
    private const array TEXT = [
        'receiver_name' => ParcelDraft::NAME_MAX,
        'receiver_phone' => 20,
        'branch_id' => 36,
        'description' => ParcelInput::DESCRIPTION_MAX,
        'notation' => ParcelInput::NOTATION_MAX,
    ];
    private const array SIDES = ['length', 'width', 'height'];

    /**
     * Те, що пішло в `POST parcel`, і прогнозована дата з відповіді.
     *
     * @param array<mixed> $answer result відповіді
     * @return array<string, string|int|float>
     */
    public static function fromDraft(ParcelDraft $draft, array $answer): array
    {
        return self::clean([
            'receiver_name' => trim((string)preg_replace('/\s+/u', ' ', $draft->receiverName)),
            'receiver_phone' => MeestPhone::normalize($draft->receiverPhone) ?? $draft->receiverPhone,
            'branch_id' => strtolower((string)($draft->branch->id ?? '')),
            'description' => $draft->description,
            'notation' => $draft->notation,
            'delivery_date' => $answer['estimatedDeliveryDate'] ?? null,
            'weight_kg' => $draft->weightKg,
            'length' => $draft->lengthCm,
            'width' => $draft->widthCm,
            'height' => $draft->heightCm,
            'volume' => $draft->volumeM3(),
            'declared' => $draft->declaredValue,
        ]);
    }

    /**
     * Посилка з відповіді Meest. Схема `parcelsList` цих полів не називає; назви — як у відповіді `POST parcel`.
     *
     * @param array<mixed> $item
     * @return array<string, string|int|float>
     */
    public static function fromApi(array $item): array
    {
        return self::clean([
            'receiver_name' => $item['receiverName'] ?? null,
            'receiver_phone' => $item['receiverPhone'] ?? null,
            'notation' => $item['notation'] ?? null,
            'delivery_date' => $item['estimatedDeliveryDate'] ?? null,
        ]);
    }

    /** @param array<string, string|int|float> $details */
    public static function encode(array $details): ?string
    {
        $details = self::clean($details);
        if ($details === []) {
            return null;
        }
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? null : $json;
    }

    /** @return array<string, string|int|float> */
    public static function decode(mixed $json): array
    {
        $raw = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($raw) && !array_is_list($raw) ? self::clean($raw) : [];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, string|int|float>
     */
    private static function clean(array $raw): array
    {
        $details = [];
        foreach (self::TEXT as $key => $limit) {
            $value = is_string($raw[$key] ?? null) ? mb_substr(trim($raw[$key]), 0, $limit) : '';
            if ($value !== '') {
                $details[$key] = $value;
            }
        }
        $date = self::date($raw['delivery_date'] ?? null);
        if ($date !== null) {
            $details['delivery_date'] = $date;
        }
        $weight = self::number($raw['weight_kg'] ?? null);
        if ($weight !== null && $weight > 0) {
            $details['weight_kg'] = round($weight, 3);
        }
        foreach (self::SIDES as $key) {
            $value = $raw[$key] ?? null;
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) {
                $details[$key] = (int)$value;
            }
        }
        $volume = self::number($raw['volume'] ?? null);
        if ($volume !== null && $volume > 0) {
            $details['volume'] = round($volume, 6);
        }
        $declared = self::number($raw['declared'] ?? null);
        if ($declared !== null && $declared >= 0) {
            $details['declared'] = round($declared, 2);
        }

        return $details;
    }

    private static function number(mixed $value): ?float
    {
        $number = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float)$value : null;

        return $number !== null && is_finite($number) ? $number : null;
    }

    /** Дата без часу: Meest пише `29.03.2019`, а може й `2019-03-29` з часом. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            [$day, $month, $year] = [$m[1], $m[2], $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            [$year, $month, $day] = [$m[1], $m[2], $m[3]];
        } else {
            return null;
        }

        return checkdate((int)$month, (int)$day, (int)$year) ? "{$year}-{$month}-{$day}" : null;
    }
}
