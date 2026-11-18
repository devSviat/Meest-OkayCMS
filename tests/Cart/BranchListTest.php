<?php

namespace Modules\Sviat\Meest\Cart;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Cart\BranchList;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Entities\GroupEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class BranchListTest extends TestCase
{
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string TODAY = '2026-11-17';

    private static function groupRows(): array
    {
        return [
            (object)['id' => 1, 'name' => 'Відділення', 'enabled' => 1, 'position' => 1, 'kinds' => '["branch"]'],
            (object)['id' => 2, 'name' => 'Поштомати', 'enabled' => 1, 'position' => 2, 'kinds' => '["postomat"]'],
        ];
    }

    private static function branch(int $n, string $number, string $kind = 'branch', string $address = 'вул. Лісова 1', array $extra = []): object
    {
        return (object)($extra + [
            'id' => sprintf('aaaaaaaa-0000-4000-8000-%012d', $n),
            'number' => $number,
            'kind' => $kind,
            'name_ua' => "Відділення №{$number}",
            'address_ua' => $address,
            'schedule_json' => null,
            'weight_place_max' => null, 'weight_total_max' => null, 'insurance_max' => null,
            'length_max' => null, 'width_max' => null, 'height_max' => null,
            'inbound_from' => null, 'inbound_to' => null, 'overloaded' => 0, 'sending_only' => 0,
        ]);
    }

    private static function branches(): array
    {
        return [
            self::branch(1, '10', 'branch', 'вул. Лісова 1'),
            self::branch(2, '2', 'branch', 'вул. Польова 15'),
            self::branch(3, '100', 'postomat', 'вул. Річна 9'),
            self::branch(4, '1', 'branch', 'вул. Польова 7'),
        ];
    }

    private static function box(): ShippingConfig
    {
        return new ShippingConfig('', '', '', '', 1.0, 30, 20, 15, true, 'cash', true, 0.0, '', '{order}');
    }

    private static function build(array $branches, array $groups, ?int $group, string $query = '', ?CartMetrics $cart = null): array
    {
        return BranchList::build($branches, $groups, $cart ?? new CartMetrics(1.0, false, 100.0, 100.0), self::box(), 100.0, self::TODAY, $group, $query);
    }

    public function testGroupsCountPoints(): void
    {
        $result = self::build(self::branches(), self::groupRows(), null);

        self::assertSame([['id' => 1, 'name' => 'Відділення', 'count' => 3], ['id' => 2, 'name' => 'Поштомати', 'count' => 1]], $result['groups']);
        self::assertSame(['1', '2', '10'], array_column($result['points'], 'number'), 'the first group by default, numbers in natural order');
    }

    public function testDisabledGroupHidden(): void
    {
        $groups = self::groupRows();
        $groups[1]->enabled = 0;

        $result = self::build(self::branches(), $groups, 2);

        self::assertSame([['id' => 1, 'name' => 'Відділення', 'count' => 3]], $result['groups']);
        self::assertSame(['1', '2', '10'], array_column($result['points'], 'number'), 'asking for a disabled group falls back to the first one');
    }

    public function testSearchByNumberAndStreet(): void
    {
        self::assertSame(['2'], array_column(self::build(self::branches(), self::groupRows(), 1, '№ 2')['points'], 'number'));
        self::assertSame(['1', '2'], array_column(self::build(self::branches(), self::groupRows(), 1, 'польова')['points'], 'number'));
        self::assertSame(['2'], array_column(self::build(self::branches(), self::groupRows(), 1, 'Польова 15')['points'], 'number'));
        self::assertSame(['1', '10', '2'], array_column(self::build(self::branches(), self::groupRows(), 1, '1')['points'], 'number'), 'exact number, numbers starting with it, then house 15');
    }

    public function testInboundPointGreyedWithDate(): void
    {
        $branches = [self::branch(1, '1', 'branch', 'вул. Лісова 1', ['inbound_from' => '2026-11-10 00:00:00', 'inbound_to' => '2026-11-20 00:00:00']), self::branch(2, '2')];

        $points = self::build($branches, self::groupRows(), 1)['points'];

        self::assertSame(['2', '1'], array_column($points, 'number'), 'fitting points come first');
        self::assertTrue($points[1]['disabled']);
        self::assertSame(['inbound'], $points[1]['reasons']);
        self::assertSame('2026-11-20', $points[1]['until']);
        self::assertFalse($points[0]['disabled']);
        self::assertNull($points[0]['until']);
    }

    public function testOverloadedIsOnlyANote(): void
    {
        $points = self::build([self::branch(1, '1', 'branch', 'x', ['overloaded' => 1])], self::groupRows(), 1)['points'];

        self::assertTrue($points[0]['overloaded']);
        self::assertFalse($points[0]['disabled']);
    }

    public function testScheduleComesFromStoredRow(): void
    {
        $json = json_encode([['day' => 'Пн', 'from' => '09:00', 'to' => '18:00', 'break_from' => null, 'break_to' => null]]);

        $points = self::build([self::branch(1, '1', 'branch', 'x', ['schedule_json' => $json])], self::groupRows(), 1)['points'];

        self::assertSame('09:00–18:00', $points[0]['schedule'][0]['hours']);
    }

    private static function recordingDatabase(): Database
    {
        return new class extends Database {
            public array $statements = [];

            public function __construct()
            {
            }

            public function __destruct()
            {
            }

            public function query(\Aura\SqlQuery\QueryInterface $query, $debug = false)
            {
                $this->statements[] = $query->getStatement();

                return true;
            }

            public function results($field = null, $mapped = null)
            {
                return [];
            }
        };
    }

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

    public function testSendingOnlyHidden(): void
    {
        $db = self::recordingDatabase();
        $entity = $this->createStub(GroupEntity::class);
        $entity->method('find')->willReturn(self::groupRows());
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);
        $groups = new GroupsStore($factory);
        $settings = $this->createStub(Settings::class);

        new BranchList($db, self::queryFactory(), $groups, $settings, static fn(): int => 0)->forCity(self::CITY, new CartMetrics(1.0, false, 1.0), 1.0, null, '');

        self::assertMatchesRegularExpression('/`b`\.`sending_only` = 0/', $db->statements[0]);
        self::assertMatchesRegularExpression('/`b`\.`city_id` = :city/', $db->statements[0]);
    }
}
