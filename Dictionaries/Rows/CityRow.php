<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries\Rows;

final class CityRow
{
    private const array TYPE_PREFIX = [
        'Місто' => 'м.',
        'Селище міського типу' => 'смт',
        'Село' => 'с.',
        'Селище' => 'с-ще',
    ];

    /**
     * @param array<string, mixed> $c запис `citySearch`
     * @param string $regionId область, яку обходили: у відповіді `regionID` може не збігатися з нею
     */
    public static function fromApi(array $c, string $regionId): ?array
    {
        $id = self::uuid($c['cityID'] ?? null);
        if ($id === null) {
            return null;
        }
        $name = self::text(self::descr($c['cityDescr'] ?? null), 255) ?? '';
        $view = self::text($c['viewCity'] ?? null, 64);

        // Без `points`: upsert не повинен обнуляти лічильник наявного міста до перерахунку; нове місто бере типове 0 колонки.
        return RowHash::with([
            'id' => $id,
            'region_id' => strtolower(trim($regionId)),
            'district_id' => self::uuid($c['districtID'] ?? null),
            'name_ua' => $name,
            'type_ua' => $view === null ? null : (self::TYPE_PREFIX[$view] ?? $view),
            'district_ua' => self::text(self::descr($c['districtDescr'] ?? null), 255),
            'region_ua' => self::text(self::descr($c['regionDescr'] ?? null), 255),
            'search_text' => NameNormalizer::searchText($name, []),
        ]);
    }

    private static function descr(mixed $descr): mixed
    {
        return is_array($descr) ? ($descr['descrUA'] ?? null) : null;
    }

    private static function uuid(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return $value === '' ? null : $value;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
