<?php

namespace Modules\Sviat\Meest\Dictionaries;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/InMemoryDictionaryStore.php';
require_once __DIR__ . '/../Support/DictionaryFixtures.php';
require_once __DIR__ . '/../Support/ContractFixtures.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\ContractFixtures;
use Modules\Sviat\Meest\Support\DictionaryFixtures;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryDictionaryStore;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\HttpTransport;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Meest\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\Meest\Dictionaries\TableLoader;
use Okay\Modules\Sviat\Meest\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;
use PHPUnit\Framework\TestCase;

class DictionaryRunnerTest extends TestCase
{
    private const int HOUR = 3600;
    private const int DAY = 86400;
    private const int T0 = 1_800_000_000;
    private const string REGION_A = DictionaryFixtures::REGION;
    private const string REGION_B = '11111111-0000-4000-8000-000000000002';
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';

    private FakeTransport $transport;
    private InMemoryState $state;
    private InMemoryMutex $mutex;
    private InMemoryDictionaryStore $store;
    private int $now = self::T0;
    private string $token = 'cabinet-token';
    private string $contract = '';

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
        $this->mutex = new InMemoryMutex();
        $this->store = new InMemoryDictionaryStore();
        $this->regions([self::REGION_A => 'Тестова']);
    }

    /** @param array<string, string> $regions */
    private function regions(array $regions): void
    {
        $list = [];
        foreach ($regions as $id => $name) {
            $list[] = ['id' => $id, 'name' => $name];
        }
        $this->state->set('regions', json_encode($list, JSON_UNESCAPED_UNICODE));
    }

    private function runner(?HttpTransport $transport = null, ?StateStorage $state = null): DictionaryRunner
    {
        $state ??= $this->state;
        $credentials = new FixedCredentials(new Credentials($this->token, $this->contract));
        $now = fn(): int => $this->now;
        $flags = new AuthFlags($state);
        $tokens = new TokenProvider($credentials, $state, $flags, $now);
        $client = new MeestClient($transport ?? $this->transport, $tokens, new RequestBudget($state, new InMemoryMutex(), $now), $credentials, new ArrayLogger());
        $keys = new KeyChecker($client, $tokens, $credentials, $state, $flags, $now);

        return new DictionaryRunner(new RegionSweep($client, $state), $this->store, $state, $this->mutex, $credentials, $keys, $now);
    }

    private function dictStatus(): DictionaryStatus
    {
        return DictionaryStatus::load($this->state);
    }

    private static function uuid(string $prefix, int $n): string
    {
        return sprintf('%s-0000-4000-8000-%012d', $prefix, $n);
    }

    private function seedBranches(string $regionId, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $id = self::uuid($regionId === self::REGION_A ? 'aaaaaaaa' : 'abababab', $i);
            $this->store->tables[DictionaryStore::BRANCHES][$id] = [
                'id' => $id, 'region_id' => $regionId, 'city_id' => self::CITY, 'sending_only' => 0, 'row_hash' => 'old', 'seen_run' => 1,
            ];
        }
    }

    private function seedCity(string $regionId, string $id = self::CITY): void
    {
        $this->store->tables[DictionaryStore::CITIES][$id] = ['id' => $id, 'region_id' => $regionId, 'points' => 0, 'row_hash' => 'old', 'seen_run' => 1];
    }

    private function answer(array $rows): void
    {
        $this->transport->json(200, ['status' => 'OK', 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => ''], 'result' => $rows]);
    }

    private function answerCities(int $count = 1): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = DictionaryFixtures::city(['cityID' => $i === 1 ? strtoupper(self::CITY) : self::uuid('bbbbbbbb', $i)]);
        }
        $this->answer($rows);
    }

    /** @param array<string, mixed> $override */
    private function answerBranches(int $count, string $prefix = 'aaaaaaaa', array $override = []): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = DictionaryFixtures::branch(['branchID' => self::uuid($prefix, $i), 'cityID' => self::CITY] + $override);
        }
        $this->answer($rows);
    }

    /** @param array<string, string> $regions */
    private function answerRegions(array $regions): void
    {
        $rows = [];
        foreach ($regions as $id => $name) {
            $rows[] = ['regionID' => $id, 'regionDescr' => ['descrUA' => $name]];
        }
        $this->answer($rows);
    }

    /** @return list<string> */
    private function paths(): array
    {
        return array_map(static fn(HttpRequest $r): string => substr($r->url, strlen(Credentials::BASE)), $this->transport->requests());
    }

    public function testEmptyRegionAnswerKeepsOldRows(): void
    {
        $this->seedBranches(self::REGION_A, 120);
        $this->seedCity(self::REGION_A);
        $this->answerCities();
        $this->answer([]);

        $this->runner()->tick();

        self::assertCount(120, $this->store->region(DictionaryStore::BRANCHES, self::REGION_A));
        self::assertSame('partial', $this->dictStatus()->lastRun['status']);
        self::assertSame([self::REGION_A], array_keys($this->dictStatus()->lastRun['skipped']));
        self::assertSame('Тестова', $this->dictStatus()->lastRun['skipped'][self::REGION_A]['name']);
        self::assertStringContainsString('0 rows against 120 stored', $this->dictStatus()->lastRun['skipped'][self::REGION_A]['reason']);
    }

    public function testHalvedRegionKeepsOldRows(): void
    {
        $this->seedBranches(self::REGION_A, 120);
        $this->seedCity(self::REGION_A);
        $this->answerCities();
        $this->answerBranches(59);

        $this->runner()->tick();

        $branches = $this->store->region(DictionaryStore::BRANCHES, self::REGION_A);
        self::assertCount(120, $branches);
        self::assertSame(['old'], array_values(array_unique(array_column($branches, 'row_hash'))));
        self::assertSame('partial', $this->dictStatus()->lastRun['status']);
    }

    public function testHalfOfStoredRowsReplacesRegion(): void
    {
        $this->seedBranches(self::REGION_A, 120);
        $this->seedCity(self::REGION_A);
        $this->answerCities();
        $this->answerBranches(60, 'cccccccc');

        $this->runner()->tick();

        self::assertCount(60, $this->store->region(DictionaryStore::BRANCHES, self::REGION_A));
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
        self::assertSame(['added' => 60, 'changed' => 0, 'deleted' => 120, 'unchanged' => 0], $this->dictStatus()->lastRun['counts']['branches']);
        self::assertSame(60, $this->store->tables[DictionaryStore::CITIES][self::CITY]['points']);
    }

    public function testCitiesCheckedBeforeAnyWrite(): void
    {
        $this->seedBranches(self::REGION_A, 120);
        $this->seedCity(self::REGION_A);
        $this->answer([]);
        $this->answerBranches(120);

        $this->runner()->tick();

        $branches = $this->store->region(DictionaryStore::BRANCHES, self::REGION_A);
        self::assertCount(120, $branches);
        self::assertSame(['old'], array_values(array_unique(array_column($branches, 'row_hash'))));
        self::assertSame([self::CITY], array_keys($this->store->region(DictionaryStore::CITIES, self::REGION_A)));
        self::assertSame('old', $this->store->tables[DictionaryStore::CITIES][self::CITY]['row_hash']);
        self::assertSame('partial', $this->dictStatus()->lastRun['status']);
    }

    public function testBranchesWithoutCitiesWriteNothingOnFirstRun(): void
    {
        $this->answer([]);
        $this->answerBranches(3);

        $this->runner()->tick();

        self::assertSame([], $this->store->tables[DictionaryStore::BRANCHES]);
        self::assertSame('partial', $this->dictStatus()->lastRun['status']);
    }

    public function testRegionThatNeverHadPointsIsNotSkipped(): void
    {
        $this->regions([self::REGION_A => 'Тестова', self::REGION_B => 'Вигадана']);
        $this->answerCities();
        $this->answerBranches(2);
        $this->answer([]);
        $this->answer([]);

        $this->runner()->tick();

        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
        self::assertSame([], $this->dictStatus()->lastRun['skipped']);
        self::assertSame([self::REGION_B => 'Вигадана'], $this->dictStatus()->emptyRegions());
    }

    public function testAllRegionsEmptyOnFreshStoreIsNotOk(): void
    {
        $this->regions([self::REGION_A => 'Тестова', self::REGION_B => 'Вигадана']);
        $this->answer([]);
        $this->answer([]);
        $this->answer([]);
        $this->answer([]);

        $this->runner()->tick();

        self::assertNotSame('ok', $this->dictStatus()->lastRun['status']);
        self::assertSame([self::REGION_A => 'Тестова', self::REGION_B => 'Вигадана'], $this->dictStatus()->emptyRegions());

        $this->now += self::DAY - 1;
        $this->runner()->tick();
        self::assertCount(4, $this->transport->requests());

        $this->now += 1;
        $this->answerCities();
        $this->answerBranches(2);
        $this->answer([]);
        $this->answer([]);
        $this->runner()->tick();
        self::assertCount(8, $this->transport->requests());
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testUnknownTypesCountedAndShown(): void
    {
        $this->answerCities();
        $this->answer([
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 1)]),
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 2), 'branchType' => 'НОВИЙ', 'branchTypeAPP' => '9']),
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 3), 'branchType' => 'НОВИЙ', 'branchTypeAPP' => '9']),
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 4), 'branchType' => 'ТОЧКА', 'branchTypeAPP' => '9']),
        ]);

        $this->runner()->tick();

        self::assertSame([self::uuid('aaaaaaaa', 1)], array_keys($this->store->region(DictionaryStore::BRANCHES, self::REGION_A)));
        self::assertSame(['НОВИЙ' => 2], $this->dictStatus()->unknownTypes());
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testSendingOnlyStoredButNotCounted(): void
    {
        $this->answerCities(2);
        $this->answer([
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 1)]),
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 2)]),
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 3), 'branchLimits' => ['sendingOnly' => true]]),
            DictionaryFixtures::branch(['branchID' => self::uuid('aaaaaaaa', 4), 'cityID' => self::uuid('bbbbbbbb', 2), 'branchLimits' => ['sendingOnly' => true]]),
        ]);

        $this->runner()->tick();

        self::assertCount(4, $this->store->region(DictionaryStore::BRANCHES, self::REGION_A));
        self::assertSame(2, $this->store->tables[DictionaryStore::CITIES][self::CITY]['points']);
        self::assertSame(0, $this->store->tables[DictionaryStore::CITIES][self::uuid('bbbbbbbb', 2)]['points']);
    }

    public function testPointsSurviveUnchangedCities(): void
    {
        $this->answerCities();
        $this->answerBranches(2);
        $this->runner()->tick();

        $this->runner()->requestRun();
        $this->answerRegions([self::REGION_A => 'Тестова']);
        $this->answerCities();
        $this->answerBranches(3);
        $this->runner()->tick();

        self::assertSame(['added' => 0, 'changed' => 0, 'deleted' => 0, 'unchanged' => 1], $this->dictStatus()->lastRun['counts']['cities']);
        self::assertSame(3, $this->store->tables[DictionaryStore::CITIES][self::CITY]['points']);
    }

    public function testSecondTickWhileRunningDoesNothing(): void
    {
        $this->mutex->held['dict'] = true;

        $this->runner()->tick();

        self::assertSame([], $this->transport->requests());
        self::assertNull($this->dictStatus()->lastRun);
    }

    public function testStaleRunningSinceIsRecordedAsInterruptedAtOnce(): void
    {
        $status = $this->dictStatus();
        $status->runningSince = $this->now - 60;
        $status->save($this->state);

        $this->runner()->tick();

        self::assertSame([], $this->transport->requests());
        self::assertNull($this->dictStatus()->runningSince);
        self::assertSame('failed', $this->dictStatus()->lastRun['status']);
        self::assertSame('interrupted', $this->dictStatus()->lastRun['error']);

        $this->now += self::HOUR - 61;
        $this->runner()->tick();
        self::assertSame([], $this->transport->requests());

        $this->now += 1;
        $this->answerCities();
        $this->answerBranches(1);
        $this->runner()->tick();
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testRequestDuringRunIsKeptAndLeavesRunStatusAlone(): void
    {
        $recording = new class ($this->state) implements StateStorage {
            /** @var list<string> */
            public array $writes = [];

            public function __construct(private readonly StateStorage $inner)
            {
            }

            public function get(string $name): ?string
            {
                return $this->inner->get($name);
            }

            public function set(string $name, ?string $value): void
            {
                $this->writes[] = $name;
                $this->inner->set($name, $value);
            }
        };
        $press = fn(): bool => $this->runner(state: $recording)->requestRun();
        $hooked = new class ($this->transport, $press) implements HttpTransport {
            public function __construct(private readonly HttpTransport $inner, private readonly \Closure $beforeBranches)
            {
            }

            public function send(HttpRequest $request): HttpResponse
            {
                if (str_ends_with($request->url, 'branchSearch')) {
                    ($this->beforeBranches)();
                }

                return $this->inner->send($request);
            }
        };
        $this->answerCities();
        $this->answerBranches(2);

        $this->runner($hooked)->tick();

        self::assertCount(1, $recording->writes);
        self::assertNotContains('dictionary_status', $recording->writes);
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
        self::assertNull($this->dictStatus()->runningSince);
        self::assertSame(self::T0, $this->dictStatus()->requestedAt);

        $this->now += 300;
        $this->answerRegions([self::REGION_A => 'Тестова']);
        $this->answerCities();
        $this->answerBranches(2);
        $this->runner()->tick();
        self::assertCount(5, $this->transport->requests());
        self::assertNull($this->dictStatus()->requestedAt);
    }

    /** До перерахунку наявне місто не повинно зникнути з кошика: upsert не чіпає `points`. */
    public function testUpsertedCityKeepsItsPointsUntilRecount(): void
    {
        $this->store->tables[DictionaryStore::CITIES][self::CITY] = [
            'id' => self::CITY, 'region_id' => self::REGION_A, 'name_ua' => 'Стара назва', 'points' => 5, 'row_hash' => 'old', 'seen_run' => 1,
        ];
        $loader = new TableLoader($this->store, DictionaryStore::CITIES, self::REGION_A, 2);

        $loader->put(CityRow::fromApi(DictionaryFixtures::city(['cityDescr' => ['descrUA' => 'Нова назва']]), self::REGION_A));
        $counts = $loader->finish();

        self::assertSame(1, $counts->changed);
        self::assertSame('Нова назва', $this->store->tables[DictionaryStore::CITIES][self::CITY]['name_ua']);
        self::assertSame(5, $this->store->tables[DictionaryStore::CITIES][self::CITY]['points']);
    }

    public function testRequestRunTriggersOnNextTick(): void
    {
        $this->answerCities();
        $this->answerBranches(3);
        $this->runner()->tick();
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);

        $this->now += self::HOUR;
        $this->runner()->tick();
        self::assertCount(2, $this->transport->requests());

        self::assertTrue($this->runner()->requestRun());
        $this->answerRegions([self::REGION_A => 'Тестова']);
        $this->answerCities();
        $this->answerBranches(3);
        $this->runner()->tick();
        self::assertSame(['citySearch', 'branchSearch', 'regionSearch', 'citySearch', 'branchSearch'], $this->paths());
        self::assertNull($this->dictStatus()->requestedAt);
        self::assertSame(['added' => 0, 'changed' => 0, 'deleted' => 0, 'unchanged' => 3], $this->dictStatus()->lastRun['counts']['branches']);
    }

    public function testRunsAgainAfterSevenDays(): void
    {
        $this->answerCities();
        $this->answerBranches(2);
        $this->runner()->tick();
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);

        $this->now += 7 * self::DAY - 1;
        $this->runner()->tick();
        self::assertCount(2, $this->transport->requests());

        $this->now += 1;
        $this->answerCities();
        $this->answerBranches(2);
        $this->runner()->tick();
        self::assertCount(4, $this->transport->requests());
    }

    public function testPartialRegionRetriedNextDay(): void
    {
        $this->seedBranches(self::REGION_A, 120);
        $this->seedCity(self::REGION_A);
        $this->answerCities();
        $this->answer([]);
        $this->runner()->tick();
        self::assertSame('partial', $this->dictStatus()->lastRun['status']);

        $this->now += self::DAY - 1;
        $this->runner()->tick();
        self::assertCount(2, $this->transport->requests());

        $this->now += 1;
        $this->answerCities();
        $this->answerBranches(120);
        $this->runner()->tick();
        self::assertCount(4, $this->transport->requests());
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testContractRefreshedOnRun(): void
    {
        $this->contract = self::CONTRACT;
        $this->transport->json(200, ContractFixtures::body());
        $this->answerCities();
        $this->answerBranches(1);

        $this->runner()->tick();

        self::assertSame(['contractClientInfo/' . self::CONTRACT, 'citySearch', 'branchSearch'], $this->paths());
        self::assertNotNull($this->state->get(KeyChecker::CONTRACT_STATE));
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testUnreachableKeepsEverything(): void
    {
        $this->regions([self::REGION_A => 'Тестова', self::REGION_B => 'Вигадана']);
        $this->seedBranches(self::REGION_A, 5);
        $this->seedCity(self::REGION_A);
        $this->seedBranches(self::REGION_B, 4);
        $this->transport->json(503, []);

        $this->runner()->tick();

        self::assertSame('failed', $this->dictStatus()->lastRun['status']);
        self::assertSame(['citySearch'], $this->paths());
        self::assertCount(5, $this->store->region(DictionaryStore::BRANCHES, self::REGION_A));
        self::assertCount(4, $this->store->region(DictionaryStore::BRANCHES, self::REGION_B));
        self::assertCount(1, $this->store->region(DictionaryStore::CITIES, self::REGION_A));
    }

    public function testFailureRetriesAfterAnHour(): void
    {
        $this->transport->json(503, []);
        $this->runner()->tick();
        self::assertSame('failed', $this->dictStatus()->lastRun['status']);

        $this->now += self::HOUR - 1;
        $this->runner()->tick();
        self::assertCount(1, $this->transport->requests());

        $this->now += 1;
        $this->answerCities();
        $this->answerBranches(1);
        $this->runner()->tick();
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testRateLimitedStopsRun(): void
    {
        $this->regions([self::REGION_A => 'Тестова', self::REGION_B => 'Вигадана']);
        $this->seedBranches(self::REGION_A, 3);
        $this->seedCity(self::REGION_A);
        $this->answerCities();
        $this->answerBranches(2, 'cccccccc');
        $this->transport->json(429, []);

        $this->runner()->tick();

        self::assertSame(['citySearch', 'branchSearch', 'citySearch'], $this->paths());
        self::assertSame('rate_limited', $this->dictStatus()->lastRun['status']);
        self::assertCount(2, $this->store->region(DictionaryStore::BRANCHES, self::REGION_A));

        $this->now += 300;
        $this->answer([DictionaryFixtures::city(['cityID' => self::uuid('bbbbbbbb', 7), 'regionID' => self::REGION_B])]);
        $this->answer([DictionaryFixtures::branch(['branchID' => self::uuid('abababab', 1), 'cityID' => self::uuid('bbbbbbbb', 7)])]);
        $this->runner()->tick();

        $requests = $this->transport->requests();
        self::assertCount(5, $requests);
        self::assertStringContainsString(self::REGION_B, (string)$requests[3]->body);
        self::assertStringContainsString(self::REGION_B, (string)$requests[4]->body);
        self::assertCount(1, $this->store->region(DictionaryStore::BRANCHES, self::REGION_B));
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
        self::assertSame(['added' => 3, 'changed' => 0, 'deleted' => 3, 'unchanged' => 0], $this->dictStatus()->lastRun['counts']['branches']);
    }

    public function testWithoutTokenMeestIsNotCalled(): void
    {
        $this->token = '';

        $this->runner()->tick();

        self::assertSame([], $this->transport->requests());
        self::assertSame('no_keys', $this->dictStatus()->lastRun['status']);
    }

    public function testRequestWithoutTokenIsRefusedAndNotQueued(): void
    {
        $this->token = '';
        $before = $this->state->values;

        self::assertFalse($this->runner()->requestRun());

        self::assertNull($this->dictStatus()->requestedAt);
        self::assertSame($before, $this->state->values);
    }
    /** Перелік областей за запитом з адмінки читається заново, але урізана відповідь не підміняє кеш. */
    public function testTruncatedRegionListOnRequestKeepsCachedRegionsAndIsPartial(): void
    {
        $cached = [self::REGION_A => 'Тестова'];
        for ($i = 2; $i <= 25; $i++) {
            $cached[self::uuid('22222222', $i)] = "Область {$i}";
        }
        $this->regions($cached);
        self::assertTrue($this->runner()->requestRun());
        $this->answerRegions(array_slice($cached, 0, 6, true));
        $this->answerCities();
        $this->answerBranches(2);
        for ($i = 2; $i <= 25; $i++) {
            $this->answer([]);
            $this->answer([]);
        }

        $this->runner()->tick();

        $paths = $this->paths();
        self::assertSame('regionSearch', $paths[0]);
        self::assertCount(1 + 25 * 2, $paths);
        self::assertCount(25, json_decode((string)$this->state->get('regions'), true));
        self::assertSame('partial', $this->dictStatus()->lastRun['status']);
        self::assertSame([], $this->dictStatus()->lastRun['skipped']);
        self::assertSame('regionSearch: 6 regions against 25 cached; the cached list is kept', $this->dictStatus()->lastRun['regions_kept']);
    }

    public function testPlausibleRegionListOnRequestReplacesCache(): void
    {
        $this->regions([self::REGION_A => 'Тестова', self::REGION_B => 'Вигадана']);
        $this->runner()->requestRun();
        $this->answerRegions([self::REGION_A => 'Тестова']);
        $this->answerCities();
        $this->answerBranches(2);

        $this->runner()->tick();

        self::assertSame(['regionSearch', 'citySearch', 'branchSearch'], $this->paths());
        self::assertSame([['id' => self::REGION_A, 'name' => 'Тестова']], json_decode((string)$this->state->get('regions'), true));
        self::assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    /** S8: текст Meest показується лише в результаті дії, у стан іде вид помилки. */
    public function testMeestTextNeverReachesStateFromSkippedRegion(): void
    {
        $this->answerCities();
        $this->transport->json(400, ['info' => ['fieldName' => '', 'message' => 'Secret text', 'messageDetails' => '']]);

        $this->runner()->tick();

        self::assertSame('partial', $this->dictStatus()->lastRun['status']);
        self::assertSame('rejected', $this->dictStatus()->lastRun['skipped'][self::REGION_A]['reason']);
        self::assertStringNotContainsString('Secret text', implode("\n", $this->state->values));
    }

    public function testMeestTextNeverReachesStateFromStoppedRun(): void
    {
        $this->state->set('regions', null);
        $this->transport->json(400, ['info' => ['fieldName' => '', 'message' => 'Secret text', 'messageDetails' => '']]);

        $this->runner()->tick();

        self::assertSame('failed', $this->dictStatus()->lastRun['status']);
        self::assertSame('rejected', $this->dictStatus()->lastRun['error']);
        self::assertStringNotContainsString('Secret text', implode("\n", $this->state->values));
    }

    public function testMeestTextNeverReachesStateFromEnvelopeError(): void
    {
        $this->answerCities();
        $this->transport->json(200, ['status' => 'Error', 'info' => ['fieldName' => '', 'message' => 'Secret text', 'messageDetails' => ''], 'result' => []]);

        $this->runner()->tick();

        self::assertStringNotContainsString('Secret text', implode("\n", $this->state->values));
    }
}
