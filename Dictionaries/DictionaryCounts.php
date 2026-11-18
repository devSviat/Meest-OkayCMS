<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries;

use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchKind;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;

/** Цифри довідників для адмінки. */
class DictionaryCounts
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    /** @return array<string, int> пункти за видами; один прохід по таблиці, а не по одному на вид */
    public function byKind(): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['kind', 'COUNT(*) AS n'])
            ->from(BranchEntity::getTable())
            ->groupBy(['kind']);

        return self::fromRows($this->db->query($select) === false ? [] : $this->db->results());
    }

    public function cities(): int
    {
        return (int)$this->entityFactory->get(CityEntity::class)->count();
    }

    /** Області, міста яких є в довіднику. */
    public function regions(): int
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['COUNT(DISTINCT region_id) AS n'])
            ->from(CityEntity::getTable());

        return $this->db->query($select) === false ? 0 : (int)$this->db->result('n');
    }

    /**
     * @param iterable<object> $rows рядки `kind`, `n`
     * @return array<string, int> кожен вид, нуль, де немає рядка
     */
    public static function fromRows(iterable $rows): array
    {
        $counts = array_fill_keys(array_map(static fn(BranchKind $kind): string => $kind->value, BranchKind::cases()), 0);
        foreach ($rows as $row) {
            if (isset($counts[$row->kind])) {
                $counts[$row->kind] = (int)$row->n;
            }
        }

        return $counts;
    }
}
