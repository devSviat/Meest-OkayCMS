<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;

/** Entity-заглушки, які застосовують фільтр так, як це робить ядро: рівність по кожному полю. */
trait FakeDictionaryEntities
{
    /**
     * @param list<array<string, mixed>> $cities
     * @param list<array<string, mixed>> $branches
     */
    private function dictionaryFactory(array $cities, array $branches): EntityFactory
    {
        $entities = [
            CityEntity::class => $this->entityOver(CityEntity::class, $cities),
            BranchEntity::class => $this->entityOver(BranchEntity::class, $branches),
        ];
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => $entities[$class]);

        return $factory;
    }

    /**
     * @param class-string<CityEntity|BranchEntity> $class
     * @param list<array<string, mixed>> $rows
     */
    private function entityOver(string $class, array $rows): CityEntity|BranchEntity
    {
        $matching = static function (array $filter) use ($rows): array {
            $conditions = array_diff_key($filter, ['limit' => 1, 'keyword' => 1]);
            $found = array_filter($rows, static function (array $row) use ($conditions): bool {
                foreach ($conditions as $field => $value) {
                    if (!array_key_exists($field, $row) || (string)$row[$field] !== (string)$value) {
                        return false;
                    }
                }

                return true;
            });
            if (isset($filter['keyword'])) {
                $found = array_filter($found, static fn(array $row): bool => str_contains((string)$row['search_text'], (string)$filter['keyword']));
            }

            return array_values($found);
        };

        $entity = $this->createStub($class);
        $entity->method('cols')->willReturnSelf();
        $entity->method('noLimit')->willReturnSelf();
        $entity->method('find')->willReturnCallback(static fn(array $filter = []) => array_map(static fn(array $row): object => (object)$row, $matching($filter)));
        $entity->method('findOne')->willReturnCallback(static function (array $filter = []) use ($matching) {
            $first = $matching($filter)[0] ?? null;

            return $first === null ? false : (object)$first;
        });

        return $entity;
    }
}
