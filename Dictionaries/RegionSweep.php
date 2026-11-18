<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Обхід країни по областях: `branchSearch {}` без фільтра не використовується, поки невідомий розмір
 * його відповіді. Перелік областей сталий, тож він кешується в стані й перечитується лише за запитом з адмінки.
 */
final class RegionSweep
{
    public const string UKRAINE = 'c35b6195-4ea3-11de-8591-001d600938f8';

    private const string STATE = 'regions';

    private ?string $implausible = null;

    public function __construct(
        private readonly MeestClient $client,
        private readonly StateStorage $state,
    ) {
    }

    /**
     * Кешований перелік читається заново лише за $refresh. Meest під навантаженням відповідає 200 з частиною
     * рядків, тож перелік, коротший за половину кешованого (порожній теж), кеш не замінює: інакше області,
     * що випали, лишилися б зі старими пунктами назавжди. Тоді обходиться кешований перелік, а причина — в implausible().
     *
     * @return list<array{id:string, name:string}>
     * @throws MeestApiException Empty, коли кешу немає, а Meest не повернула жодної області
     */
    public function regions(bool $refresh = false): array
    {
        $this->implausible = null;
        $cached = self::valid(json_decode((string)$this->state->get(self::STATE), true));
        if ($cached !== [] && !$refresh) {
            return $cached;
        }

        $regions = $this->fetch();
        if ($cached === [] && $regions === []) {
            throw MeestApiException::of(ApiErrorKind::Empty, 'POST regionSearch: no regions');
        }
        if (count($regions) * 2 < count($cached)) {
            $this->implausible = 'regionSearch: ' . count($regions) . ' regions against ' . count($cached) . ' cached; the cached list is kept';

            return $cached;
        }
        $this->state->set(self::STATE, json_encode($regions, JSON_UNESCAPED_UNICODE));

        return $regions;
    }

    /** Чому останній regions() лишив кешований перелік замість відповіді Meest; null — не лишав. */
    public function implausible(): ?string
    {
        return $this->implausible;
    }

    /** @return list<array{id:string, name:string}> */
    private function fetch(): array
    {
        $regions = [];
        foreach (MeestClient::rows($this->client->call('POST', 'regionSearch', ['filters' => ['countryID' => self::UKRAINE]])) as $row) {
            $id = is_string($row['regionID'] ?? null) ? strtolower(trim($row['regionID'])) : '';
            if ($id === '') {
                continue;
            }
            $descr = is_array($row['regionDescr'] ?? null) ? $row['regionDescr'] : [];
            $name = is_string($descr['descrUA'] ?? null) ? trim($descr['descrUA']) : '';
            $regions[] = ['id' => $id, 'name' => $name === '' ? $id : $name];
        }

        return $regions;
    }

    /** @return list<array<mixed>> */
    public function cities(string $regionId): array
    {
        return MeestClient::rows($this->client->call('POST', 'citySearch', [
            'isDirectory' => true,
            'filters' => ['regionID' => $regionId, 'IsBranchInCity' => true],
        ]));
    }

    /** @return list<array<mixed>> */
    public function branches(string $regionId): array
    {
        return MeestClient::rows($this->client->call('POST', 'branchSearch', ['filters' => ['regionID' => $regionId]]));
    }

    /** @return list<array{id:string, name:string}> */
    private static function valid(mixed $cached): array
    {
        if (!is_array($cached)) {
            return [];
        }
        $regions = [];
        foreach ($cached as $region) {
            if (is_array($region) && is_string($region['id'] ?? null) && $region['id'] !== '' && is_string($region['name'] ?? null)) {
                $regions[] = ['id' => $region['id'], 'name' => $region['name']];
            }
        }

        return $regions;
    }
}
