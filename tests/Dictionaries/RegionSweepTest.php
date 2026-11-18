<?php

namespace Modules\Sviat\Meest\Dictionaries;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Dictionaries\RegionSweep;
use PHPUnit\Framework\TestCase;

class RegionSweepTest extends TestCase
{
    private const string REGION_A = '11111111-0000-4000-8000-000000000001';
    private const string REGION_B = '11111111-0000-4000-8000-000000000002';

    private FakeTransport $transport;
    private InMemoryState $state;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
    }

    private function sweep(): RegionSweep
    {
        $credentials = new FixedCredentials(new Credentials('cabinet-token', ''));
        $now = static fn(): int => 1_800_000_000;
        $tokens = new TokenProvider($credentials, $this->state, new AuthFlags($this->state), $now);
        $client = new MeestClient($this->transport, $tokens, new RequestBudget($this->state, new InMemoryMutex(), $now), $credentials, new ArrayLogger());

        return new RegionSweep($client, $this->state);
    }

    private function answer(array $rows): void
    {
        $this->transport->json(200, ['status' => 'OK', 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => ''], 'result' => $rows]);
    }

    private function regionRows(): array
    {
        return [
            ['regionID' => strtoupper(self::REGION_A), 'regionDescr' => ['descrUA' => 'Тестова', 'descrEN' => 'Testova']],
            ['regionID' => self::REGION_B, 'regionDescr' => ['descrUA' => 'Вигадана']],
        ];
    }

    public function testRequestBodies(): void
    {
        $this->answer($this->regionRows());
        $this->answer([]);
        $this->answer([]);
        $sweep = $this->sweep();

        $sweep->regions();
        $sweep->cities(self::REGION_A);
        $sweep->branches(self::REGION_A);

        $requests = $this->transport->requests();
        self::assertCount(3, $requests);
        self::assertSame(['POST', 'POST', 'POST'], array_map(static fn($r): string => $r->method, $requests));
        self::assertStringEndsWith('/openAPI/regionSearch', $requests[0]->url);
        self::assertSame('{"filters":{"countryID":"c35b6195-4ea3-11de-8591-001d600938f8"}}', $requests[0]->body);
        self::assertStringEndsWith('/openAPI/citySearch', $requests[1]->url);
        self::assertSame('{"isDirectory":true,"filters":{"regionID":"' . self::REGION_A . '","IsBranchInCity":true}}', $requests[1]->body);
        self::assertStringEndsWith('/openAPI/branchSearch', $requests[2]->url);
        self::assertSame('{"filters":{"regionID":"' . self::REGION_A . '"}}', $requests[2]->body);
        self::assertSame(RegionSweep::UKRAINE, 'c35b6195-4ea3-11de-8591-001d600938f8');
    }

    public function testRegionsCached(): void
    {
        $this->answer($this->regionRows());

        $expected = [['id' => self::REGION_A, 'name' => 'Тестова'], ['id' => self::REGION_B, 'name' => 'Вигадана']];
        self::assertSame($expected, $this->sweep()->regions());
        self::assertSame($expected, $this->sweep()->regions());
        self::assertCount(1, $this->transport->requests());
        self::assertNotNull($this->state->get('regions'));
    }

    public function testEmptyRegionListIsAnErrorAndNotCached(): void
    {
        $this->answer([]);

        try {
            $this->sweep()->regions();
            self::fail('an empty region list must stop the run');
        } catch (MeestApiException $e) {
            self::assertSame(ApiErrorKind::Empty, $e->kind);
        }
        self::assertNull($this->state->get('regions'));
    }
    /** @return list<array{id:string, name:string}> */
    private function cache(int $count): array
    {
        $regions = [];
        for ($i = 1; $i <= $count; $i++) {
            $regions[] = ['id' => sprintf('22222222-0000-4000-8000-%012d', $i), 'name' => "Область {$i}"];
        }
        $this->state->set('regions', json_encode($regions, JSON_UNESCAPED_UNICODE));

        return $regions;
    }

    private function answerRegions(int $count): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['regionID' => sprintf('33333333-0000-4000-8000-%012d', $i), 'regionDescr' => ['descrUA' => "Нова {$i}"]];
        }
        $this->answer($rows);
    }

    public function testCachedListIsNotRequestedWithoutRefresh(): void
    {
        $cached = $this->cache(25);

        self::assertSame($cached, $this->sweep()->regions());
        self::assertSame([], $this->transport->requests());
    }

    public function testRefreshReplacesCacheWithPlausibleList(): void
    {
        $this->cache(25);
        $this->answerRegions(13);
        $sweep = $this->sweep();

        $regions = $sweep->regions(refresh: true);

        self::assertCount(13, $regions);
        self::assertNull($sweep->implausible());
        self::assertSame($regions, json_decode((string)$this->state->get('regions'), true));
    }

    public function testTruncatedRefreshKeepsCachedList(): void
    {
        $cached = $this->cache(25);
        $this->answerRegions(6);
        $sweep = $this->sweep();

        self::assertSame($cached, $sweep->regions(refresh: true));
        self::assertSame('regionSearch: 6 regions against 25 cached; the cached list is kept', $sweep->implausible());
        self::assertSame($cached, json_decode((string)$this->state->get('regions'), true));
    }

    public function testLessThanHalfIsImplausible(): void
    {
        $cached = $this->cache(25);
        $this->answerRegions(12);
        $sweep = $this->sweep();

        self::assertSame($cached, $sweep->regions(refresh: true));
        self::assertNotNull($sweep->implausible());
    }

    public function testEmptyRefreshKeepsCachedList(): void
    {
        $cached = $this->cache(25);
        $this->answer([]);
        $sweep = $this->sweep();

        self::assertSame($cached, $sweep->regions(refresh: true));
        self::assertNotNull($sweep->implausible());
    }
}
