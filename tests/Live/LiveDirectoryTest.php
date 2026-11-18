<?php

namespace Modules\Sviat\Meest\Live;

require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\CurlTransport;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchRow;
use Okay\Modules\Sviat\Meest\KeyCheck\ContractInfo;
use PHPUnit\Framework\TestCase;

/**
 * Живий прогін лише на читання: змінні задає власник у своїй оболонці. Повідомлення assert містять ключі, типи
 * й кількості, але ніколи не значення з відповідей Meest.
 */
class LiveDirectoryTest extends TestCase
{
    private MeestClient $client;
    private RegionSweep $sweep;
    private string $contractId;

    protected function setUp(): void
    {
        $token = (string)getenv('MEEST_API_TOKEN');
        $contractId = (string)getenv('MEEST_CONTRACT_ID');
        if ($token === '' || $contractId === '') {
            self::markTestSkipped('MEEST_API_TOKEN/MEEST_CONTRACT_ID не задані');
        }

        $this->contractId = $contractId;
        $credentials = new FixedCredentials(new Credentials($token, $contractId));
        $state = new InMemoryState();
        $now = static fn(): int => time();
        $tokens = new TokenProvider($credentials, $state, new AuthFlags($state), $now);
        $this->client = new MeestClient(
            new CurlTransport(),
            $tokens,
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            new ArrayLogger(),
        );
        $this->sweep = new RegionSweep($this->client, $state);
    }

    public function testContractClientInfoAnswers(): void
    {
        $body = $this->client->plain('contractClientInfo/' . strtolower($this->contractId));
        $info = ContractInfo::fromApi($body);

        self::assertIsBool($info->deleted, 'deleted is ' . get_debug_type($info->deleted));
    }

    public function testRegionSearchGivesUkraineRegions(): void
    {
        $regions = $this->sweep->regions();

        self::assertGreaterThanOrEqual(20, count($regions), 'regions: ' . count($regions));
        self::assertLessThanOrEqual(30, count($regions), 'regions: ' . count($regions));
        $invalid = array_filter($regions, static fn(array $region): bool => !Credentials::validContractId($region['id']));
        self::assertCount(0, $invalid, 'region ids that are not UUID: ' . count($invalid));
    }

    public function testCitiesAndBranchesOfOneRegion(): void
    {
        $regionId = $this->sweep->regions()[0]['id'];

        $cities = $this->sweep->cities($regionId);
        $branches = $this->sweep->branches($regionId);

        self::assertNotEmpty($cities, 'cities of the first region: 0');
        self::assertNotEmpty($branches, 'branches of the first region: 0');
        $rows = array_filter(array_map(static fn(array $b): ?array => BranchRow::fromApi($b, $regionId), $branches));
        $unknownTypes = array_values(array_unique(array_filter(array_map(BranchRow::unknownType(...), $branches))));
        self::assertNotEmpty(
            $rows,
            'branches: ' . count($branches) . ', none became a row; unknown types: ' . implode(', ', $unknownTypes),
        );
    }
}
