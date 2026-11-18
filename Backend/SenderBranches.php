<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\NameNormalizer;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;

/** Місто й відділення, з якого магазин відправляє посилки: вибір із довідника. */
class SenderBranches
{
    public const int MIN_QUERY = 2;
    public const int CITY_LIMIT = 20;

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * Пункти лише на видачу (`receiving_only`) посилок не приймають, тож відправник їх не обирає.
     *
     * @return list<array{id:string, number:string, title:string, address:string}>
     */
    public function forCity(string $cityId): array
    {
        if ($cityId === '') {
            return [];
        }
        $rows = $this->entityFactory->get(BranchEntity::class)
            ->cols(['id', 'number', 'name_ua', 'address_ua'])
            ->noLimit()
            ->find(['city_id' => $cityId, 'receiving_only' => 0]) ?: [];

        $options = array_map(static fn(object $row): array => [
            'id' => (string)$row->id,
            'number' => (string)$row->number,
            'title' => trim($row->number . ' · ' . $row->name_ua . ($row->address_ua ? ', ' . $row->address_ua : '')),
            'address' => (string)$row->address_ua,
        ], $rows);
        // Номер — рядок у таблиці, і SQL-сортування дало б 1, 10, 100, 2.
        usort($options, static fn(array $a, array $b): int => strnatcmp($a['number'], $b['number']) ?: strcmp($a['id'], $b['id']));

        return $options;
    }

    /** Надісланий вручну id пункту з іншого міста чи лише на видачу не проходить, хоча й існує в довіднику. */
    public function isSendable(string $cityId, string $branchId): bool
    {
        if ($cityId === '' || $branchId === '') {
            return false;
        }

        return $this->entityFactory->get(BranchEntity::class)->cols(['id'])
            ->findOne(['id' => $branchId, 'city_id' => $cityId, 'receiving_only' => 0]) !== false;
    }

    public function cityExists(string $cityId): bool
    {
        return $cityId !== '' && $this->entityFactory->get(CityEntity::class)->cols(['id'])->findOne(['id' => $cityId]) !== false;
    }

    /** @return array{id:string, name:string, type:string, region:string}|null */
    public function city(string $cityId): ?array
    {
        $city = $cityId === '' ? false : $this->entityFactory->get(CityEntity::class)
            ->cols(['id', 'name_ua', 'type_ua', 'region_ua'])
            ->findOne(['id' => $cityId]);

        return $city ? [
            'id' => (string)$city->id,
            'name' => (string)$city->name_ua,
            'type' => (string)($city->type_ua ?? ''),
            'region' => (string)($city->region_ua ?? ''),
        ] : null;
    }

    /** @return list<array{id:string, name:string, type:string, region:string}> */
    public function searchCities(string $query): array
    {
        $normalized = NameNormalizer::normalize($query);
        if (mb_strlen($normalized) < self::MIN_QUERY) {
            return [];
        }
        $rows = $this->entityFactory->get(CityEntity::class)
            ->cols(['id', 'name_ua', 'type_ua', 'region_ua'])
            ->find(['keyword' => $normalized, 'limit' => self::CITY_LIMIT]) ?: [];

        return array_values(array_map(static fn(object $row): array => [
            'id' => (string)$row->id,
            'name' => (string)$row->name_ua,
            'type' => (string)($row->type_ua ?? ''),
            'region' => (string)($row->region_ua ?? ''),
        ], $rows));
    }
}
