<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchRow;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\Meest\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\Storage\Mutex;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Одне завдання планувальника виконує всю роботу з довідниками, кнопку в адмінці включно: кнопка лише
 * залишає запит, тож два запуски ніколи не перетинаються.
 */
final class DictionaryRunner
{
    private const string LOCK = 'dict';
    /** Дельт у Meest немає, кожен запуск — повний обхід країни, тож раз на тиждень. */
    private const int EVERY = 7 * 86400;
    /** Область, що лишилася зі старими рядками, повторюється наступного дня, а не щогодини: кожен запуск — повний обхід країни. */
    private const int RETRY_PARTIAL = 86400;
    private const int RETRY_AFTER_FAILURE = 3600;
    /** Тайм-аут завдання планувальника. */
    public const int TASK_TIMEOUT = 3600;

    /** Збій, який зламає й решту областей: обхід зупиняється, а не збирає 25 однакових помилок. */
    private const array STOPS_RUN = [ApiErrorKind::BadToken, ApiErrorKind::NotConfigured, ApiErrorKind::Unreachable, ApiErrorKind::ServerError];

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RegionSweep $sweep,
        private readonly DictionaryStore $store,
        private readonly StateStorage $state,
        private readonly Mutex $mutex,
        private readonly CredentialsProvider $credentials,
        private readonly KeyChecker $keys,
        private readonly \Closure $now,
    ) {
    }

    /** @return bool false — без token кабінету запит не ставиться в чергу: такий запуск однаково нічого не завантажить */
    public function requestRun(): bool
    {
        if (!$this->credentials->get()->configured()) {
            return false;
        }
        DictionaryStatus::request($this->state, ($this->now)());

        return true;
    }

    /** Викликається планувальником кожні 5 хвилин; працює, лише коли щось пора виконати. */
    public function tick(): void
    {
        // Перевірка runningSince і його запис — два кроки: два процеси, що стартували разом, пройшли б обидва.
        if (!$this->mutex->acquire(self::LOCK)) {
            return;
        }
        try {
            $this->tickLocked();
        } finally {
            $this->mutex->release(self::LOCK);
        }
    }

    private function tickLocked(): void
    {
        $now = ($this->now)();
        $status = DictionaryStatus::load($this->state);
        if ($status->runningSince !== null) {
            // Блокування тримається весь запуск, тож runningSince під ним лишив процес, убитий до `finally`
            // (тайм-аут, брак пам'яті). Це збій: повтор — за годину від старту вбитого запуску.
            $status->lastRun = self::result($status->runningSince, $status->runningSince, DictionaryStatus::FAILED, 'interrupted');
            $status->runningSince = null;
            $status->save($this->state);
        }
        if (!$this->isDue($status, $now)) {
            return;
        }

        // Запуск, зупинений бюджетом запитів, продовжується з тієї області, на якій зупинився; кнопка починає новий.
        $requested = $status->requestedAt !== null;
        $resumed = !$requested && ($status->lastRun['status'] ?? null) === DictionaryStatus::RATE_LIMITED
            ? $status->lastRun
            : null;
        $status->serveRequest();
        if (!$this->credentials->get()->configured()) {
            $status->lastRun = self::result($now, $now, DictionaryStatus::NO_KEYS, 'Meest token is not set');
            $status->save($this->state);

            return;
        }
        $status->runningSince = $now;
        $status->save($this->state);

        $run = $resumed === null
            ? self::result($now, $now, DictionaryStatus::FAILED, null)
            : ['status' => DictionaryStatus::FAILED, 'error' => null, 'pending' => []] + $resumed;
        $only = $resumed['pending'] ?? [];
        try {
            $this->keys->refreshContract();
            $this->sweepRegions($now, $run, is_array($only) ? $only : [], $requested);
            $run['status'] = match (true) {
                $run['pending'] !== [] => DictionaryStatus::RATE_LIMITED,
                $run['skipped'] === [] && ($run['regions_kept'] ?? null) === null && self::storedBranches($run) > 0 => DictionaryStatus::OK,
                default => DictionaryStatus::PARTIAL,
            };
        } catch (MeestApiException $e) {
            if ($e->kind === ApiErrorKind::RateLimited) {
                // Зупинка ще до першої області: наступний тік обходить те саме, що мав обійти цей.
                $run['status'] = DictionaryStatus::RATE_LIMITED;
                $run['pending'] = is_array($only) ? $only : [];
            }
            // S8: текст Meest показується лише в результаті дії; у стані він лежав би до наступного запуску.
            $run['error'] = $e->kind->value;
        } catch (\Throwable $e) {
            $run['error'] = MeestApiException::mask($e->getMessage(), $this->credentials->get()->secrets());
        } finally {
            $run['finished'] = ($this->now)();
            $status->lastRun = $run;
            $status->runningSince = null;
            $status->save($this->state);
        }
    }

    private function isDue(DictionaryStatus $status, int $now): bool
    {
        if ($status->requestedAt !== null || $status->lastRun === null) {
            return true;
        }
        $wait = match ($status->lastRun['status']) {
            DictionaryStatus::OK => self::EVERY,
            DictionaryStatus::PARTIAL => self::RETRY_PARTIAL,
            DictionaryStatus::RATE_LIMITED => 0,
            default => self::RETRY_AFTER_FAILURE,
        };

        return $now - (int)$status->lastRun['finished'] >= $wait;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $only області, які лишилися від запуску, зупиненого бюджетом; порожній — усі
     * @param bool $refreshRegions запуск за запитом з адмінки перечитує перелік областей
     */
    private function sweepRegions(int $run, array &$result, array $only, bool $refreshRegions): void
    {
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $branches = UpdateCounts::fromArray(is_array($counts[DictionaryStore::BRANCHES] ?? null) ? $counts[DictionaryStore::BRANCHES] : []);
        $cities = UpdateCounts::fromArray(is_array($counts[DictionaryStore::CITIES] ?? null) ? $counts[DictionaryStore::CITIES] : []);
        $regions = $this->sweep->regions($refreshRegions);
        $result['regions_kept'] ??= $this->sweep->implausible();
        if ($only !== []) {
            $regions = array_values(array_filter($regions, static fn(array $region): bool => in_array($region['id'], $only, true)));
        }

        foreach ($regions as $i => $region) {
            try {
                [$regionBranches, $regionCities] = $this->region($region['id'], $run, $result['unknown_types']);
                if ($regionBranches->isEmpty() && $regionCities->isEmpty()) {
                    $result['empty_regions'][$region['id']] = $region['name'];
                }
            } catch (ImplausibleRegion $e) {
                $result['skipped'][$region['id']] = ['name' => $region['name'], 'reason' => $e->getMessage()];
                continue;
            } catch (MeestApiException $e) {
                if ($e->kind === ApiErrorKind::RateLimited) {
                    $result['pending'] = array_column(array_slice($regions, $i), 'id');

                    return;
                }
                if (in_array($e->kind, self::STOPS_RUN, true)) {
                    throw $e;
                }
                $result['skipped'][$region['id']] = ['name' => $region['name'], 'reason' => $e->kind->value];
                continue;
            }
            $branches = $branches->plus($regionBranches);
            $cities = $cities->plus($regionCities);
            $result['counts'] = [DictionaryStore::BRANCHES => $branches->toArray(), DictionaryStore::CITIES => $cities->toArray()];
        }
    }

    /**
     * @param array<string, int> $unknownTypes доповнюється типами пунктів, яких BranchKind не знає
     * @return array{0: UpdateCounts, 1: UpdateCounts} пункти, міста
     */
    private function region(string $regionId, int $run, array &$unknownTypes): array
    {
        $cities = [];
        foreach ($this->sweep->cities($regionId) as $entry) {
            $city = CityRow::fromApi($entry, $regionId);
            if ($city !== null) {
                $cities[$city['id']] = $city;
            }
        }
        $branches = [];
        foreach ($this->sweep->branches($regionId) as $entry) {
            $branch = BranchRow::fromApi($entry, $regionId);
            if ($branch !== null) {
                $branches[$branch['id']] = $branch;
                continue;
            }
            $type = BranchRow::unknownType($entry);
            if ($type !== null) {
                $unknownTypes[$type] = ($unknownTypes[$type] ?? 0) + 1;
            }
        }

        $branchLoader = new TableLoader($this->store, DictionaryStore::BRANCHES, $regionId, $run);
        $cityLoader = new TableLoader($this->store, DictionaryStore::CITIES, $regionId, $run);
        // Обидві перевірки — до першого запису: область не повинна лишитися з новими пунктами й порожніми містами.
        TableLoader::assertPlausibleRegion($cityLoader->storedAtStart(), count($cities));
        TableLoader::assertPlausibleRegion($branchLoader->storedAtStart(), count($branches));
        // На першому запуску збережених рядків ще немає, і перевірка вище пропустила б пункти без жодного міста.
        if ($branches !== [] && $cities === []) {
            throw new ImplausibleRegion(count($branches) . ' points without a single city; the region keeps its rows');
        }
        foreach ($cities as $city) {
            $cityLoader->put($city);
        }
        foreach ($branches as $branch) {
            $branchLoader->put($branch);
        }
        $cityCounts = $cityLoader->finish();
        $branchCounts = $branchLoader->finish();
        // Нове місто вставляється з points = 0, а пункти області змінилися: лічильник перераховується після кожної області.
        $this->store->recountPoints([$regionId]);

        return [$branchCounts, $cityCounts];
    }

    /**
     * Порожня область без збережених рядків правдоподібна (окуповані області), але порожня вся країна —
     * ні: так виглядає і свіже встановлення, коли Meest під навантаженням відповідає порожньо скрізь.
     *
     * @param array<string, mixed> $run
     */
    private static function storedBranches(array $run): int
    {
        return UpdateCounts::fromArray(is_array($run['counts'][DictionaryStore::BRANCHES] ?? null) ? $run['counts'][DictionaryStore::BRANCHES] : [])->kept();
    }

    /**
     * @return array{started:int, finished:int, status:string, counts:array<string, array<string, int>>, skipped:array<string, array{name:string, reason:string}>,
     *     pending:list<string>, unknown_types:array<string, int>, empty_regions:array<string, string>, regions_kept:?string, error:?string}
     */
    private static function result(int $started, int $finished, string $status, ?string $error): array
    {
        return [
            'started' => $started,
            'finished' => $finished,
            'status' => $status,
            'counts' => [],
            'skipped' => [],
            'pending' => [],
            'unknown_types' => [],
            'empty_regions' => [],
            'regions_kept' => null,
            'error' => $error,
        ];
    }
}
