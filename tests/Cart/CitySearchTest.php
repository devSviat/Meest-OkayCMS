<?php

namespace Modules\Sviat\Meest\Cart;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Modules\Sviat\Meest\Cart\CitySearch;
use PHPUnit\Framework\TestCase;

class CitySearchTest extends TestCase
{
    private const string A = 'bbbbbbbb-0000-4000-8000-000000000011';
    private const string B = 'bbbbbbbb-0000-4000-8000-000000000012';

    private static function row(string $id, string $name, string $district, string $region): object
    {
        return (object)['id' => $id, 'name_ua' => $name, 'type_ua' => 'с.', 'district_ua' => $district, 'region_ua' => $region];
    }

    public function testNamesakesShowDistrictAndRegion(): void
    {
        $found = CitySearch::present([
            self::row(self::A, 'Вигадане', 'Перший', 'Тестова'),
            self::row(self::B, 'Вигадане', 'Другий', 'Тестова'),
        ]);

        self::assertSame([
            ['id' => self::A, 'name' => 'Вигадане', 'type' => 'с.', 'district' => 'Перший', 'region' => 'Тестова'],
            ['id' => self::B, 'name' => 'Вигадане', 'type' => 'с.', 'district' => 'Другий', 'region' => 'Тестова'],
        ], $found, 'two rows, told apart by district; the choice is by UUID');
    }

    public function testMissingPartsBecomeEmptyStrings(): void
    {
        $row = (object)['id' => self::A, 'name_ua' => 'Вигадане', 'type_ua' => null, 'district_ua' => null, 'region_ua' => null];

        self::assertSame([['id' => self::A, 'name' => 'Вигадане', 'type' => '', 'district' => '', 'region' => '']], CitySearch::present([$row]));
    }

    public function testQueryIsNormalisedLikeSearchText(): void
    {
        self::assertSame(['вигадане'], CitySearch::words('  ВИГАДАНЕ '));
        self::assertSame(['мар\'їне'], CitySearch::words('Мар’їне'));
        self::assertSame([], CitySearch::words('місто'), 'a bare type matches everything');
        self::assertSame(['100\%'], CitySearch::words('100%'), 'LIKE wildcards are escaped');
    }

    private static function recordingDatabase(): Database
    {
        return new class extends Database {
            public array $statements = [];
            public array $binds = [];

            public function __construct()
            {
            }

            public function __destruct()
            {
            }

            public function query(\Aura\SqlQuery\QueryInterface $query, $debug = false)
            {
                $this->statements[] = $query->getStatement();
                $this->binds[] = $query->getBindValues();

                return true;
            }

            public function results($field = null, $mapped = null)
            {
                return [];
            }
        };
    }

    /** Справжній Select без конструктора: AbstractQuery інакше бере Database з ServiceLocator, а в CI бази немає. */
    private static function queryFactory(): QueryFactory
    {
        return new class (new AuraQueryFactory('mysql')) extends QueryFactory {
            public function newSelect()
            {
                $select = new \ReflectionClass(Select::class)->newInstanceWithoutConstructor();
                new \ReflectionProperty(Select::class, 'queryObject')->setValue($select, new AuraQueryFactory('mysql')->newSelect());

                return $select;
            }
        };
    }

    public function testOnlyCitiesWithPoints(): void
    {
        $db = self::recordingDatabase();

        (new CitySearch($db, self::queryFactory()))->find('вигадане');

        self::assertMatchesRegularExpression('/`c`\.`points` > 0/', $db->statements[0]);
    }

    public function testSearchFromWordStartNotAnywhere(): void
    {
        $db = self::recordingDatabase();

        (new CitySearch($db, self::queryFactory()))->find('гадане');

        self::assertContains('гадане%', $db->binds[0]);
        self::assertContains('% гадане%', $db->binds[0]);
        self::assertNotContains('%гадане%', $db->binds[0]);
    }

    public function testDefaultLimitIsTwentyAndSortedInSql(): void
    {
        $db = self::recordingDatabase();

        (new CitySearch($db, self::queryFactory()))->find('вигадане');

        self::assertStringContainsString('LIMIT 20', $db->statements[0]);
        self::assertStringContainsString('`c`.`points` DESC', $db->statements[0]);
    }

    public function testOneLetterQueryReturnsNothingWithoutQuerying(): void
    {
        $db = self::recordingDatabase();
        $search = new CitySearch($db, self::queryFactory());

        self::assertSame([], $search->find('к'));
        self::assertSame([], $search->find('місто'));
        self::assertSame([], $db->statements);
    }
}
