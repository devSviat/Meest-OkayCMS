<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStore;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ParcelStoreTest extends TestCase
{
    private const string NOW = '2026-11-17 10:00:00';

    private function store(ParcelEntity $entity): ParcelStore
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new ParcelStore($factory);
    }

    private function entity(): ParcelEntity&MockObject
    {
        return $this->createMock(ParcelEntity::class);
    }

    public function testReserveNumbersTheAttemptById(): void
    {
        $entity = $this->entity();
        $entity->method('add')->willReturn(7);
        $entity->method('findOne')->willReturn(
            (object)['id' => '7', 'order_id' => '1042', 'parcel_number' => null, 'state' => PendingParcel::PENDING],
            (object)['id' => '7', 'order_id' => '1042', 'parcel_number' => '1042-7', 'state' => PendingParcel::PENDING],
        );
        $entity->expects(self::once())->method('update')->with(7, ['parcel_number' => '1042-7']);
        $entity->expects(self::never())->method('delete');

        self::assertSame('1042-7', $this->store($entity)->reserve(1042, self::NOW));
    }

    public function testDuplicateReservationIsRefused(): void
    {
        $entity = $this->entity();
        $entity->method('add')->willReturn(false);
        $entity->expects(self::never())->method('update');

        self::assertNull($this->store($entity)->reserve(1042, self::NOW));
    }

    /**
     * Невдала вставка на стоковому ядрі може віддати id попередньої вставки: рядок іншого запиту, що ще не встиг
     * записати номер, не стає резервом цього.
     */
    public function testStaleInsertIdIsNotAReservation(): void
    {
        $entity = $this->entity();
        $entity->method('add')->willReturn(3);
        $entity->method('findOne')->willReturn((object)['id' => '5', 'order_id' => '1042', 'parcel_number' => null, 'state' => PendingParcel::PENDING]);
        $entity->expects(self::never())->method('update');

        self::assertNull($this->store($entity)->reserve(1042, self::NOW));
    }

    /** Сторінка списку замовлень не тягне JSON знімка й історії. */
    public function testOrdersListReadsOnlyTheColumnsOfTheRow(): void
    {
        $entity = $this->entity();
        $entity->expects(self::once())->method('cols')->with(ParcelStore::LIST_COLUMNS)->willReturnSelf();
        $entity->method('find')->willReturn([(object)['order_id' => '7', 'barcode' => '711-2550636']]);

        self::assertSame([7], array_keys($this->store($entity)->forOrders([7, 0])));
        self::assertNotContains('details', ParcelStore::LIST_COLUMNS);
        self::assertNotContains('history', ParcelStore::LIST_COLUMNS);
    }

    /** Дубль штрихкоду update() ковтає: успіх видно лише з перечитаного рядка. */
    public function testCompleteChecksTheSavedRow(): void
    {
        $pending = (object)['id' => '5', 'order_id' => '1042', 'state' => PendingParcel::PENDING, 'barcode' => null, 'parcel_id' => null];
        $entity = $this->entity();
        $entity->method('findOne')->willReturn($pending, $pending);
        $entity->expects(self::once())->method('update');

        self::assertFalse($this->store($entity)->complete(1042, ['parcel_id' => 'ffffffff-0000-4000-8000-000000000001', 'barcode' => '711-2550636']));
    }

    /** UPDATE номера ядро теж ковтає: без номера в перечитаному рядку резерв знімається, і запит до Meest не йде. */
    public function testUnsavedNumberIsNotAReservation(): void
    {
        $row = (object)['id' => '7', 'order_id' => '1042', 'parcel_number' => null, 'state' => PendingParcel::PENDING];
        $entity = $this->entity();
        $entity->method('add')->willReturn(7);
        $entity->method('findOne')->willReturn($row, $row, $row);
        $entity->expects(self::once())->method('update')->with(7, ['parcel_number' => '1042-7']);
        $entity->expects(self::once())->method('delete')->with([7]);

        self::assertNull($this->store($entity)->reserve(1042, self::NOW));
    }

    private const string BARCODE = '711-2550636';

    /** @return array<string, mixed> */
    private static function attached(): array
    {
        return ['parcel_id' => null, 'barcode' => self::BARCODE, 'created_at' => self::NOW, 'checked_at' => self::NOW];
    }

    public function testAttachNewRowIsConfirmedByReread(): void
    {
        $entity = $this->entity();
        $entity->method('findOne')->willReturn(false, (object)['id' => '9', 'order_id' => '1042', 'state' => PendingParcel::CREATED, 'barcode' => self::BARCODE]);
        $entity->expects(self::once())->method('add')->with(self::callback(
            static fn(array $values): bool => $values['created_at'] === self::NOW && $values['order_id'] === 1042,
        ))->willReturn(9);

        self::assertTrue($this->store($entity)->attach(1042, self::attached()));
    }

    /** Дубль штрихкоду на стоковому ядрі може повернути id попередньої вставки: true без запису брехав би менеджеру. */
    public function testAttachStaleInsertIdIsNotSaved(): void
    {
        $entity = $this->entity();
        $entity->method('findOne')->willReturn(false, false);
        $entity->expects(self::once())->method('add')->willReturn(3);

        self::assertFalse($this->store($entity)->attach(1042, self::attached()));
    }

    /** Тік шукає рядки за штрихкодами подій: з дефісом і без — той самий ключ. */
    public function testByBarcodesIgnoresDashes(): void
    {
        $entity = $this->entity();
        $entity->expects(self::once())->method('find')
            ->with(['barcode_key' => ['7112550636', 'CV999999999UA'], 'limit' => 4])
            ->willReturn([(object)['order_id' => '7', 'barcode' => '7112550636']]);

        $rows = $this->store($entity)->byBarcodes(['711-2550636', '7112550636', 'CV999999999UA']);

        self::assertSame(['7112550636'], array_map(strval(...), array_keys($rows)));
    }

    public function testOrderOfBarcodeIgnoresDashes(): void
    {
        $entity = $this->entity();
        $entity->expects(self::once())->method('findOne')
            ->with(['barcode_key' => ['7112550636']])
            ->willReturn((object)['order_id' => '7', 'barcode' => '711-2550636']);

        self::assertSame(7, $this->store($entity)->orderOfBarcode('7112550636'));
    }

    /** Вибірка однієї колонки дає значення, а не рядок: так відповідає справжня сутність. */
    public function testOldestUnfinishedReadsTheSingleColumnValue(): void
    {
        $entity = self::singleColumnEntity('2026-11-01 09:30:00');

        self::assertSame('2026-11-01 09:30:00', $this->store($entity)->oldestUnfinishedCreatedAt('2026-09-01 00:00:00'));
        self::assertNull($this->store(self::singleColumnEntity(false))->oldestUnfinishedCreatedAt('2026-09-01 00:00:00'));
    }

    /** order() у сутності final і читає select, тож він підміняється записувачем, що приймає будь-який виклик. */
    private static function singleColumnEntity(string|false $value): ParcelEntity
    {
        $entity = new class extends ParcelEntity {
            public string|false $value = false;

            public function __construct()
            {
            }

            public function cols(array $cols)
            {
                return $this;
            }

            public function findOne(array $filter = [])
            {
                return $this->value;
            }
        };
        $entity->value = $value;
        new \ReflectionProperty(\Okay\Core\Entity\Entity::class, 'select')->setValue($entity, new class {
            public function __call(string $name, array $arguments): static
            {
                return $this;
            }
        });

        return $entity;
    }
}
