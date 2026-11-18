<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries\Store;

/**
 * Масові записи рядків довідників. $table — 'branches' або 'cities'; ключі — UUID у нижньому регістрі.
 * Обидві таблиці мають `region_id`: область — одиниця заміни, бо Meest віддає пункти по одній області.
 * Кожен метод кидає виняток на невдалому запиті: тихо провалений запис, а за ним deleteNotSeen(),
 * стерли б рядки, які ще дійсні.
 */
interface DictionaryStore
{
    public const string BRANCHES = 'branches';
    public const string CITIES = 'cities';

    /** @return array<string, string> id → row_hash рядків області */
    public function hashes(string $table, string $regionId): array;

    /** @param list<array<string, mixed>> $rows вставлені або замінені разом із `region_id` */
    public function upsert(string $table, array $rows, int $run): void;

    /** Позначає незмінені рядки як присутні в запуску $run. @param list<string> $ids */
    public function touch(string $table, array $ids, int $run): void;

    /** Видаляє рядки області, яких запуск $run не побачив; викликати лише після правдоподібного обходу області. */
    public function deleteNotSeen(string $table, string $regionId, int $run): int;

    /**
     * `cities.points` міст цих областей — кількість пунктів, які показує кошик (без `sending_only`).
     *
     * @param list<string> $regionIds
     */
    public function recountPoints(array $regionIds): void;
}
