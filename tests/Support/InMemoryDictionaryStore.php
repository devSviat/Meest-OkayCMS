<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Modules\Sviat\Meest\Dictionaries\Store\DictionaryStore;

final class InMemoryDictionaryStore implements DictionaryStore
{
    /** @var array<string, array<string, array>> */
    public array $tables = [self::BRANCHES => [], self::CITIES => []];

    public function hashes(string $table, string $regionId): array
    {
        $hashes = [];
        foreach ($this->tables[$table] as $id => $row) {
            if ($row['region_id'] === $regionId) {
                $hashes[(string)$id] = $row['row_hash'];
            }
        }

        return $hashes;
    }

    public function upsert(string $table, array $rows, int $run): void
    {
        // Як INSERT … ON DUPLICATE KEY UPDATE: колонки, яких немає в рядку, у наявного запису лишаються.
        foreach ($rows as $row) {
            $this->tables[$table][$row['id']] = array_replace($this->tables[$table][$row['id']] ?? [], $row, ['seen_run' => $run]);
        }
    }

    public function touch(string $table, array $ids, int $run): void
    {
        foreach ($ids as $id) {
            $this->tables[$table][$id]['seen_run'] = $run;
        }
    }

    public function deleteNotSeen(string $table, string $regionId, int $run): int
    {
        $before = count($this->tables[$table]);
        $this->tables[$table] = array_filter(
            $this->tables[$table],
            static fn(array $row): bool => $row['region_id'] !== $regionId || $row['seen_run'] === $run,
        );

        return $before - count($this->tables[$table]);
    }

    public function recountPoints(array $regionIds): void
    {
        foreach ($this->tables[self::CITIES] as $id => $city) {
            if (!in_array($city['region_id'], $regionIds, true)) {
                continue;
            }
            $points = 0;
            foreach ($this->tables[self::BRANCHES] as $branch) {
                if ($branch['city_id'] === (string)$id && (int)($branch['sending_only'] ?? 0) === 0) {
                    $points++;
                }
            }
            $this->tables[self::CITIES][$id]['points'] = $points;
        }
    }

    /** @return array<string, array> */
    public function region(string $table, string $regionId): array
    {
        return array_filter($this->tables[$table], static fn(array $row): bool => $row['region_id'] === $regionId);
    }
}
