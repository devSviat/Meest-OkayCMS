<?php

namespace Okay\Modules\Sviat\Meest\Orders;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\Meest\Init\Init;

/** Один `SHOW TABLES LIKE` на запит; викликається лише зі сторінки способу доставки в адмінці. */
final class DbOrderTable implements OrderTable
{
    private ?bool $exists = null;

    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
        private readonly Config $config,
    ) {
    }

    public function exists(): bool
    {
        if ($this->exists === null) {
            $prefix = (string)$this->config->get('db_prefix');
            $like = addcslashes($prefix . 'sviat__meest__', '\\%_') . '%';
            $query = $this->queryFactory->newSqlQuery()->setStatement('SHOW TABLES LIKE :name')->bindValue('name', $like);
            // Збій запиту не повинен ховати попередження: таблиць немає, доки база не сказала протилежне.
            $existing = $this->db->query($query) !== false
                ? array_map(static fn($row): string => (string)current((array)$row), $this->db->results())
                : [];
            $this->exists = self::allPresent($existing, $prefix);
        }

        return $this->exists;
    }

    /** @param list<string> $existing назви таблиць бази з префіксом */
    public static function allPresent(array $existing, string $prefix): bool
    {
        return Init::missingTables(Init::tables(), $existing, $prefix) === [];
    }
}
