<?php

namespace Modules\Sviat\Meest\Cart;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Meest\Cart\PopularCities;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use PHPUnit\Framework\TestCase;

class PopularCitiesTest extends TestCase
{
    private const string A = '11111111-1111-4111-8111-111111111111';
    private const string B = '22222222-2222-4222-8222-222222222222';
    private const string C = '33333333-3333-4333-8333-333333333333';

    /** @param list<string> $known id міст, які є в таблиці */
    private function popular(InMemoryState $state, array $known): PopularCities
    {
        $rows = [];
        foreach ($known as $id) {
            $rows[$id] = (object)['id' => $id, 'name_ua' => 'Місто ' . substr($id, 0, 2), 'type_ua' => 'м.', 'region_ua' => 'Вигадана'];
        }
        $entity = $this->createStub(CityEntity::class);
        $entity->method('cols')->willReturnSelf();
        $entity->method('noLimit')->willReturnSelf();
        $entity->method('find')->willReturnCallback(static fn(array $filter = []) => array_values(array_intersect_key($rows, array_flip($filter['id'] ?? []))));
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new PopularCities($state, $factory);
    }

    public function testUnknownIdsDropped(): void
    {
        $state = new InMemoryState();
        $popular = $this->popular($state, [self::A, self::B]);

        $popular->save([self::B, 'not-a-city', self::C, strtoupper(self::A), self::B, '', 7]);

        self::assertSame([self::B, self::A], PopularCities::stored($state));
        self::assertSame([self::B, self::A], array_column($popular->list(), 'id'));
    }

    public function testListSkipsCitiesRemovedFromTheDictionary(): void
    {
        $state = new InMemoryState();
        PopularCities::store($state, [self::A, self::C, self::B]);

        self::assertSame([self::A, self::B], array_column($this->popular($state, [self::A, self::B])->list(), 'id'));
    }

    public function testCleanKeepsOrderDropsDuplicatesAndUnknown(): void
    {
        self::assertSame([self::C, self::A], PopularCities::clean([self::C, self::A, strtoupper(self::C), 'x', 5, self::B], [self::A, self::C]));
    }

    public function testCleanCapsTheList(): void
    {
        $ids = array_map(static fn(int $n): string => sprintf('%08d-0000-4000-8000-000000000000', $n), range(1, 30));

        self::assertCount(PopularCities::MAX, PopularCities::clean($ids, $ids));
    }

    public function testCorruptStateIsEmpty(): void
    {
        $state = new InMemoryState();
        $state->set('popular_cities', '{"not":"a list"}');
        self::assertSame([], PopularCities::stored($state));

        $state->set('popular_cities', '[1, "' . self::A . '", ""]');
        self::assertSame([self::A], PopularCities::stored($state));
        self::assertSame([], PopularCities::stored(new InMemoryState()));
    }

    public function testButtonCarriesNameAndType(): void
    {
        self::assertSame(['id' => self::A, 'label' => 'Київ', 'type' => 'м.'], PopularCities::button(['id' => self::A, 'name' => 'Київ', 'type' => 'м.']));
    }
}
