<?php

namespace Modules\Sviat\Meest\Integration;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;
use Okay\Modules\Sviat\Meest\Integration\MeestOrders;
use PHPUnit\Framework\TestCase;

class MeestOrdersTest extends TestCase
{
    private static function row(array $over = []): object
    {
        return (object)($over + [
            'order_id' => 7, 'barcode' => '0501234-5678', 'state' => 'created', 'status_group' => 'in_transit',
            'event_at' => '2026-11-10 12:00:00', 'arrived_at' => null,
        ]);
    }

    public function testPendingAndUncertainAreSkipped(): void
    {
        self::assertNull(MeestOrders::parcel(self::row(['state' => 'pending'])));
        self::assertNull(MeestOrders::parcel(self::row(['state' => 'uncertain'])));
        self::assertNull(MeestOrders::parcel(self::row(['barcode' => ''])));
        self::assertNull(MeestOrders::parcel(self::row(['barcode' => null])));
    }

    public function testAttachedByBarcodeIsVisible(): void
    {
        $parcel = MeestOrders::parcel(self::row(['parcel_id' => null, 'receiver_pay' => null]));

        self::assertSame(7, $parcel?->orderId);
    }

    public function testCarriesBarcodeGroupAndDate(): void
    {
        $parcel = MeestOrders::parcel(self::row());

        self::assertSame('0501234-5678', $parcel->barcode);
        self::assertSame('in_transit', $parcel->group);
        self::assertSame('2026-11-10 12:00:00', $parcel->statusAt);
        self::assertNull(MeestOrders::parcel(self::row(['status_group' => null, 'event_at' => null]))->group);
    }

    public function testArrivedAtOnlyAtBranch(): void
    {
        $arrivedAt = '2026-11-11 09:00:00';

        self::assertSame($arrivedAt, MeestOrders::parcel(self::row(['status_group' => 'arrived', 'arrived_at' => $arrivedAt]))->arrivedAt);
        self::assertSame($arrivedAt, MeestOrders::parcel(self::row(['status_group' => 'storage', 'arrived_at' => $arrivedAt]))->arrivedAt);
        self::assertNull(MeestOrders::parcel(self::row(['status_group' => 'in_transit', 'arrived_at' => $arrivedAt]))->arrivedAt);
    }

    public function testUnfinishedFilterParams(): void
    {
        $captured = [];
        $parcels = $this->createStub(ParcelEntity::class);
        $parcels->method('noLimit')->willReturnSelf();
        $parcels->method('cols')->willReturnSelf();
        $parcels->method('find')->willReturnCallback(static function (array $filter) use (&$captured): array {
            $captured = $filter;

            return [];
        });
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($parcels);

        (new MeestOrders($factory))->unfinished();

        $since = strtotime($captured['unfinished']);
        self::assertEqualsWithDelta(time() - 60 * 86400, $since, 5);
    }

    public function testUnfinishedFilterSql(): void
    {
        $recorder = new class {
            public array $where = [];
            public array $bound = [];

            public function where(string $sql): static
            {
                $this->where[] = $sql;

                return $this;
            }

            public function bindValue(string $name, mixed $value): static
            {
                $this->bound[$name] = $value;

                return $this;
            }
        };
        $entity = new \ReflectionClass(ParcelEntity::class)->newInstanceWithoutConstructor();
        $select = new \ReflectionProperty(ParcelEntity::class, 'select');
        $select->setValue($entity, $recorder);
        new \ReflectionMethod(ParcelEntity::class, 'filter__unfinished')->invoke($entity, '2026-09-18 00:00:00');
        $sql = implode(' AND ', $recorder->where);

        self::assertStringContainsString("status_group NOT IN ('received','returned','cancelled')", $sql);
        self::assertStringContainsString('status_group IS NULL', $sql);
        self::assertStringContainsString("barcode <> ''", $sql);
        self::assertSame(['meest_unfinished_since' => '2026-09-18 00:00:00'], $recorder->bound);
    }

    public function testAddressCityIsFirstSegment(): void
    {
        $address = MeestOrders::address((object)[
            'city_name' => 'м. Вигадане, Тестовий р-н, Тестова обл.', 'branch_title' => 'Відділення 1',
            'branch_address' => 'вул. Тестова, 1', 'branch_number' => '12',
        ]);

        self::assertSame('м. Вигадане', $address->city);
        self::assertSame('Відділення 1, вул. Тестова, 1', $address->line);
        self::assertSame('12', $address->number);
        self::assertSame('Відділення 1', MeestOrders::address((object)['city_name' => 'с. Тест', 'branch_title' => 'Відділення 1', 'branch_address' => null, 'branch_number' => '3'])->line);
        self::assertNull(MeestOrders::address(null));
    }

    public function testAddressNullForForeignDelivery(): void
    {
        self::assertNull($this->facade(orderDelivery: 3, snapshotDelivery: 5)->addressOf(7));
        self::assertSame('м. Тест', $this->facade(orderDelivery: 5, snapshotDelivery: 5)->addressOf(7)?->city);
    }

    public function testWithBarcodeSqlShape(): void
    {
        self::assertSame(
            "SELECT order_id FROM __sviat__meest__parcels WHERE state = 'created' AND barcode IS NOT NULL AND barcode <> ''",
            MeestOrders::withBarcodeSql(),
        );
    }

    private function facade(int $orderDelivery, int $snapshotDelivery): MeestOrders
    {
        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('cols')->willReturnSelf();
        $orders->method('get')->willReturn((object)['delivery_id' => $orderDelivery]);
        $snapshots = $this->createStub(OrderDeliveryEntity::class);
        $snapshots->method('findOne')->willReturn((object)[
            'delivery_id' => $snapshotDelivery, 'city_name' => 'м. Тест, р-н, обл.', 'branch_title' => 'В1',
            'branch_address' => null, 'branch_number' => '5',
        ]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => $class === OrdersEntity::class ? $orders : $snapshots);

        return new MeestOrders($factory);
    }
}
