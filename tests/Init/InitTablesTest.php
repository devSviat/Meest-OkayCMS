<?php

namespace Modules\Sviat\Meest\Init;

require_once __DIR__ . '/../Support/DictionaryFixtures.php';

use Modules\Sviat\Meest\Support\DictionaryFixtures;
use Okay\Core\Modules\EntityField;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchRow;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Entities\GroupEntity;
use Okay\Modules\Sviat\Meest\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;
use Okay\Modules\Sviat\Meest\Orders\DbOrderTable;
use Okay\Modules\Sviat\Meest\Init\Init;
use PHPUnit\Framework\TestCase;

class InitTablesTest extends TestCase
{
    public function testInstallCreatesModuleTables(): void
    {
        $tables = array_map(static fn(string $entity): string => $entity::getTable(), array_keys(Init::tables()));

        self::assertSame(['__sviat__meest__state', '__sviat__meest__cities', '__sviat__meest__branches', '__sviat__meest__groups', '__sviat__meest__order_delivery', '__sviat__meest__parcels'], $tables);
    }

    /** Масовий запис іде повз entity, тож кожен ключ рядка мусить бути колонкою, а кожна колонка — полем entity. */
    public function testBranchColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[BranchEntity::class];
        $columns = self::names($fields);

        self::assertSame(BranchEntity::getFields(), $columns);
        $row = BranchRow::fromApi(DictionaryFixtures::branch(), DictionaryFixtures::REGION);
        self::assertNotNull($row);
        self::assertSame([], array_values(array_diff([...array_keys($row), 'seen_run', 'updated_at'], $columns)));
        self::assertSame(['id'], self::names(array_values(array_filter($fields, static fn(EntityField $field): bool => $field->isPrimaryKey()))));
        self::assertSame(['region_id', 'city_id'], self::indexed($fields, EntityField::INDEX));
        self::assertSame('varchar(36)', self::type($fields, 'id'));
        self::assertSame('varchar(36)', self::type($fields, 'city_id'));
    }

    public function testCityColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[CityEntity::class];
        $columns = self::names($fields);

        self::assertSame(CityEntity::getFields(), $columns);
        $row = CityRow::fromApi(DictionaryFixtures::city(), DictionaryFixtures::REGION);
        self::assertNotNull($row);
        self::assertSame([], array_values(array_diff([...array_keys($row), 'seen_run', 'updated_at'], $columns)));
        self::assertSame(['region_id'], self::indexed($fields, EntityField::INDEX));
        self::assertSame('varchar(36)', self::type($fields, 'id'));
    }

    public function testGroupColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[GroupEntity::class];

        self::assertSame(GroupEntity::getFields(), array_values(array_diff(self::names($fields), ['name'])));
        self::assertSame(['id'], self::names(array_values(array_filter($fields, static fn(EntityField $field): bool => $field->isPrimaryKey()))));
        self::assertSame(['name'], self::names(array_values(array_filter($fields, static fn(EntityField $field): bool => $field->isLangField()))));
    }

    public function testOrderDeliveryColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[OrderDeliveryEntity::class];

        self::assertSame(OrderDeliveryEntity::getFields(), self::names($fields));
        self::assertSame(['order_id'], self::indexed($fields, EntityField::INDEX_UNIQUE));
        self::assertSame('varchar(36)', self::type($fields, 'branch_id'));
        self::assertSame('varchar(36)', self::type($fields, 'city_id'));
    }

    /** Унікальні ключі — і є захист від другої посилки: перевірка в коді гонку двох запитів не ловить. */
    public function testParcelColumnsMatchTheEntityAndGuardDuplicates(): void
    {
        $fields = Init::tables()[ParcelEntity::class];

        self::assertSame(ParcelEntity::getFields(), self::names($fields));
        self::assertSame(['order_id', 'parcel_id', 'barcode', 'parcel_number'], self::indexed($fields, EntityField::INDEX_UNIQUE));
        self::assertSame(['status_group'], self::indexed($fields, EntityField::INDEX));
        $composite = $fields[array_search('status_group', self::names($fields), true)]->getIndexes()[EntityField::INDEX]['fields'];
        self::assertSame(['checked_at'], self::names($composite));
        foreach (['parcel_id', 'barcode', 'parcel_number', 'contract_id', 'details', 'history', 'receiver_pay', 'reserved_at'] as $nullable) {
            self::assertTrue(self::field($fields, $nullable)->isNullable(), $nullable);
        }
        self::assertFalse(self::field($fields, 'order_id')->isNullable());
        self::assertSame('varchar(36)', self::type($fields, 'parcel_id'));
        self::assertSame('varchar(20)', self::type($fields, 'barcode'));
        self::assertSame(EntityField::TYPE_TEXT, self::field($fields, 'details')->getType());
        self::assertSame(EntityField::TYPE_MEDIUMTEXT, self::field($fields, 'history')->getType());
    }

    public function testNoLegacyFilesRemain(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest';

        foreach (['Helpers', 'VO', 'Plugins', 'Init/SmartyPlugins.php', 'design/js/meest.js', 'Controllers/MeestController.php', 'Backend/Controllers/MeestAdmin.php'] as $legacy) {
            self::assertFileDoesNotExist("{$root}/{$legacy}");
        }
    }

    /** @param list<EntityField> $fields */
    private static function names(array $fields): array
    {
        return array_map(static fn(EntityField $field): string => $field->getName(), $fields);
    }

    /** @param list<EntityField> $fields */
    private static function indexed(array $fields, string $index): array
    {
        return self::names(array_values(array_filter($fields, static fn(EntityField $field): bool => isset($field->getIndexes()[$index]))));
    }

    /** @param list<EntityField> $fields */
    private static function field(array $fields, string $name): EntityField
    {
        foreach ($fields as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }
        self::fail($name);
    }

    /** @param list<EntityField> $fields */
    private static function type(array $fields, string $name): string
    {
        foreach ($fields as $field) {
            if ($field->getName() === $name) {
                return strtolower((string)$field->getType());
            }
        }

        return '';
    }

    /** Installer виконує update_x_y_z лише до версії з module.json: метод новішої версії мовчки не запуститься. */
    public function testEveryUpdateMethodIsWithinTheModuleVersion(): void
    {
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Init/module.json'), true);
        $updates = [];
        foreach (new \ReflectionClass(Init::class)->getMethods() as $method) {
            if (preg_match('/^update_(\d+)_(\d+)_(\d+)$/', $method->name, $m)) {
                $updates[] = "{$m[1]}.{$m[2]}.{$m[3]}";
            }
        }

        self::assertContains('1.1.0', $updates);
        self::assertContains('1.2.0', $updates);
        foreach ($updates as $version) {
            self::assertTrue(version_compare($version, $manifest['version'], '<='), $version);
        }
    }

    /** Модуль 1.0.0 уже встановлено без таблиці знімків: її створює оновлення, бо install() вдруге не виконується. */
    public function testUpdateFromOneZeroCreatesOrderDeliveryTable(): void
    {
        $init = new \ReflectionClass(TableRecordingInit::class)->newInstanceWithoutConstructor();

        $init->update_1_1_0();

        self::assertArrayHasKey(OrderDeliveryEntity::class, $init->created);
        self::assertEquals(Init::tables(), $init->created, 'the same single source as install()');
    }

    /** Встановлена 1.1.0 не має таблиці посилок: її створює оновлення до 1.2.0. */
    public function testUpdateFromOneOneCreatesParcelTable(): void
    {
        $init = new \ReflectionClass(TableRecordingInit::class)->newInstanceWithoutConstructor();

        $init->update_1_2_0();

        self::assertArrayHasKey(ParcelEntity::class, $init->created);
        self::assertEquals(Init::tables(), $init->created, 'the same single source as install()');
    }

    /** Таблиці, що вже є (свіжа установка виконує і install(), і кожен update_*), не створюються вдруге. */
    public function testOnlyMissingTablesAreCreated(): void
    {
        $tables = Init::tables();
        $after10 = ['ok_sviat__meest__state', 'ok_sviat__meest__cities', 'ok_sviat__meest__branches', 'ok_sviat__meest__groups', 'ok_lang_sviat__meest__groups'];
        $after11 = [...$after10, 'ok_sviat__meest__order_delivery'];

        self::assertSame([OrderDeliveryEntity::class, ParcelEntity::class], array_keys(Init::missingTables($tables, $after10, 'ok_')));
        self::assertSame([ParcelEntity::class], array_keys(Init::missingTables($tables, $after11, 'ok_')));
        self::assertSame([], Init::missingTables($tables, [...$after11, 'ok_sviat__meest__parcels'], 'ok_'));
    }

    /** Попередження «натисніть Оновити» на способі доставки тримається, доки бракує будь-якої таблиці модуля. */
    public function testUpdateWarningUntilEveryTableExists(): void
    {
        $after11 = ['ok_sviat__meest__state', 'ok_sviat__meest__cities', 'ok_sviat__meest__branches', 'ok_sviat__meest__groups', 'ok_sviat__meest__order_delivery'];

        self::assertFalse(DbOrderTable::allPresent($after11, 'ok_'), '1.2.0 files on a 1.1.0 database');
        self::assertFalse(DbOrderTable::allPresent(['ok_sviat__meest__parcels'], 'ok_'));
        self::assertTrue(DbOrderTable::allPresent([...$after11, 'ok_sviat__meest__parcels'], 'ok_'));
    }
}

/** Init без ядра: записує, які таблиці просять створити. */
final class TableRecordingInit extends Init
{
    /** @var array<class-string, list<EntityField>> */
    public array $created = [];

    protected function createMissingTables(array $tables): void
    {
        $this->created = $tables;
    }
}
