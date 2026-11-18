<?php

namespace Okay\Modules\Sviat\Meest\Init;

use Okay\Admin\Helpers\BackendDeliveriesHelper;
use Okay\Admin\Helpers\BackendOrdersHelper;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Entities\LanguagesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\NotifyHelper;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Entities\GroupEntity;
use Okay\Modules\Sviat\Meest\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;
use Okay\Modules\Sviat\Meest\Extenders\BackendExtender;
use Okay\Modules\Sviat\Meest\Extenders\FrontExtender;
use Okay\Modules\Sviat\Meest\Groups\DefaultGroups;
use Okay\Modules\Sviat\Meest\Orders\DeliveryFilterHub;
use Okay\Modules\Sviat\Meest\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Meest\Orders\OrdersEntityFilters;
use Okay\Modules\Sviat\Meest\Parcels\ParcelTracking;
use Okay\Modules\Sviat\Meest\Storage\StateEntity;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('MeestSettingsAdmin');
        $this->setModuleType(MODULE_TYPE_DELIVERY);

        $this->createMissingTables(self::tables());
        $this->addDefaultGroups();
    }

    /**
     * 1.0.0 уже встановлено без таблиці знімків замовлень, а install() вдруге не виконується. На свіжій
     * установці Installer викликає це після install(): createMissingTables() пропускає наявні таблиці.
     */
    public function update_1_1_0(): void
    {
        $this->createMissingTables(self::tables());
    }

    /** 1.1.0 уже встановлено без таблиці посилок; як і update_1_1_0(), створює лише відсутні таблиці. */
    public function update_1_2_0(): void
    {
        $this->createMissingTables(self::tables());
    }

    public function init()
    {
        $this->addFrontBlock('front_cart_delivery', 'cart_delivery.tpl');
        $this->addFrontBlock('front_scripts_after_validate', 'cart_validation.tpl');
        $this->addFrontBlock('front_email_order_user_contact_info', 'email_delivery.tpl');
        $this->addBackendBlock('email_order_admin_contact_info', 'email_delivery.tpl');
        $this->registerQueueExtension(
            [DeliveriesHelper::class, 'getCartDeliveriesList'],
            [FrontExtender::class, 'getCartDeliveriesList']
        );
        $this->registerChainExtension(
            [DeliveriesHelper::class, 'getCartDeliveriesList'],
            [FrontExtender::class, 'applyQuotes']
        );
        $this->registerChainExtension(
            [DeliveriesHelper::class, 'prepareDeliveryPriceInfo'],
            [FrontExtender::class, 'orderPrice']
        );
        $this->registerChainExtension(
            [ValidateHelper::class, 'getCartValidateError'],
            [FrontExtender::class, 'getCartValidateError']
        );
        $this->registerQueueExtension(
            [OrdersHelper::class, 'finalCreateOrderProcedure'],
            [FrontExtender::class, 'saveOrderDelivery']
        );
        foreach ([[OrdersHelper::class, 'getOrderPaymentMethodsList'], [NotifyHelper::class, 'finalEmailOrderUser'], [NotifyHelper::class, 'finalEmailOrderAdmin']] as $hook) {
            $this->registerQueueExtension($hook, [FrontExtender::class, 'assignOrderDelivery']);
        }

        $this->registerBackendController('MeestSettingsAdmin');
        $this->addBackendControllerPermission('MeestSettingsAdmin', 'delivery');

        $this->registerQueueExtension([BackendDeliveriesHelper::class, 'getDelivery'], [BackendExtender::class, 'deliveryWarning']);
        $this->addBackendBlock('delivery_price_block', 'delivery_free_warning.tpl');

        // Збереження замовлення ядром знімок не чіпає: хуків на шляху збереження немає, знімок змінює лише OrderPointAdmin.
        $this->registerQueueExtension([BackendOrdersHelper::class, 'findOrder'], [BackendExtender::class, 'assignOrderDelivery']);
        $this->registerQueueExtension([BackendOrdersHelper::class, 'delete'], [BackendExtender::class, 'deleteOrders']);
        // Форма посилки стоїть над пунктом видачі; картка наявної посилки — під товарами, як накладна NP.
        $this->addBackendBlock('order_contact', 'parcel_side.tpl');
        $this->addBackendBlock('order_contact', 'order_delivery.tpl');
        $this->addBackendBlock('order_custom_block', 'parcel_main.tpl');
        // Select «Статус доставки» і пошук за номером дає хаб Sviat/DeliveryFilter; на buildFilter — лише масові дії.
        $this->registerChainExtension([BackendOrdersHelper::class, 'buildFilter'], [BackendExtender::class, 'listFilter']);
        $this->registerQueueExtension([BackendOrdersHelper::class, 'findOrders'], [BackendExtender::class, 'listOrders']);
        foreach (DeliveryFilterHub::hooks(DeliveryFilterHub::HELPER) as [$expandable, $extension]) {
            $this->registerChainExtension($expandable, $extension);
        }
        $this->registerEntityFilter(OrdersEntity::class, 'meest_without_parcel', OrdersEntityFilters::class, 'filter__meest_without_parcel');
        $this->registerEntityFilter(OrdersEntity::class, 'meest_status_groups', OrdersEntityFilters::class, 'filter__meest_status_groups');
        $this->addBackendBlock('orders_list_name', 'orders_status.tpl');
        $this->registerBackendController('OrderPointAdmin');
        $this->addBackendControllerPermission('OrderPointAdmin', 'orders');
        $this->registerBackendController('ParcelAdmin');
        $this->addBackendControllerPermission('ParcelAdmin', 'orders');

        // Кожні 5 хвилин — лише дешева перевірка; runner сам вирішує, чи пора працювати,
        // тож кнопку в адмінці обслуговують за кілька хвилин, а тижневому оновленню не потрібен свій слот.
        $this->registerSchedule(
            new Schedule([DictionaryRunner::class, 'tick'])
                ->name('Meest: dictionaries')
                ->time('*/5 * * * *')
                ->overlap(false)
                ->timeout(DictionaryRunner::TASK_TIMEOUT)
        );
        // Дешева перевірка кожні 15 хвилин; сама робота виконується раз на день (див. OrderRowsCleanup::tick).
        $this->registerSchedule(
            new Schedule([OrderRowsCleanup::class, 'tick'])
                ->name('Meest: rows of deleted orders')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(300)
        );
        $this->registerSchedule(
            new Schedule([ParcelTracking::class, 'tick'])
                ->name('Meest: tracking')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(600)
        );
    }

    /** @return array<class-string, list<EntityField>> */
    public static function tables(): array
    {
        return [
            StateEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('name')->setTypeVarchar(64, false)->setIndexUnique(),
                new EntityField('value')->setTypeMediumText()->setNullable(),
                new EntityField('updated_at')->setTypeDatetime(false),
            ],
        ] + self::dictionaryTables() + [
            GroupEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('position')->setTypeInt(11, false)->setDefault(0),
                new EntityField('enabled')->setTypeTinyInt(1, false)->setDefault(1),
                new EntityField('kinds')->setTypeText(),
                new EntityField('name')->setTypeVarchar(255)->setIsLang(),
            ],
            OrderDeliveryEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('order_id')->setTypeInt(11, false)->setIndexUnique(),
                new EntityField('delivery_id')->setTypeInt(11, false),
                new EntityField('city_id')->setTypeVarchar(36, false),
                new EntityField('city_name')->setTypeVarchar(255, false),
                new EntityField('branch_id')->setTypeVarchar(36, false),
                new EntityField('branch_number')->setTypeVarchar(16, false),
                new EntityField('branch_kind')->setTypeVarchar(16, false),
                new EntityField('branch_title')->setTypeVarchar(255, false),
                new EntityField('branch_address')->setTypeVarchar(255, false),
                new EntityField('price')->setTypeDecimal('10,2'),
                new EntityField('price_source')->setTypeVarchar(16),
                // EntityField ядра не має типу date; пишеться лише Y-m-d (OrderDeliveryStore::pricing).
                new EntityField('estimated_date')->setTypeDatetime(),
                new EntityField('created_at')->setTypeDatetime(false),
            ],
            ParcelEntity::class => self::parcelColumns(),
        ];
    }

    /** @return list<EntityField> */
    private static function parcelColumns(): array
    {
        $checkedAt = new EntityField('checked_at')->setTypeDatetime();

        return [
            new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            // Унікальні ключі — захист від другої посилки: вставку чи запис дубля база відхиляє.
            new EntityField('order_id')->setTypeInt(11, false)->setIndexUnique(),
            new EntityField('parcel_id')->setTypeVarchar(36)->setIndexUnique(),
            new EntityField('barcode')->setTypeVarchar(20)->setIndexUnique(),
            new EntityField('parcel_number')->setTypeVarchar(32)->setIndexUnique(),
            new EntityField('contract_id')->setTypeVarchar(36),
            new EntityField('state')->setTypeVarchar(16, false),
            new EntityField('status_code')->setTypeInt(11),
            new EntityField('status_group')->setTypeVarchar(16)->setIndex(null, $checkedAt),
            new EntityField('event_name')->setTypeVarchar(255),
            new EntityField('event_at')->setTypeDatetime(),
            new EntityField('arrived_at')->setTypeDatetime(),
            new EntityField('delivery_price')->setTypeDecimal('10,2'),
            new EntityField('declared_value')->setTypeDecimal('10,2'),
            new EntityField('receiver_pay')->setTypeTinyInt(1),
            new EntityField('pay_type')->setTypeVarchar(8),
            new EntityField('details')->setTypeText()->setNullable(),
            new EntityField('history')->setTypeMediumText()->setNullable(),
            new EntityField('created_at')->setTypeDatetime(false),
            new EntityField('reserved_at')->setTypeDatetime(),
            $checkedAt,
        ];
    }

    /** @return array<class-string, list<EntityField>> міста з пунктами й пункти; ключі — UUID Meest */
    public static function dictionaryTables(): array
    {
        $loaded = static fn(): array => [
            new EntityField('row_hash')->setTypeVarchar(40, false),
            new EntityField('seen_run')->setTypeInt(11, false)->setDefault(0),
            new EntityField('updated_at')->setTypeDatetime(false),
        ];

        return [
            CityEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeVarchar(36, false),
                new EntityField('region_id')->setTypeVarchar(36, false)->setIndex(),
                new EntityField('district_id')->setTypeVarchar(36),
                new EntityField('name_ua')->setTypeVarchar(255, false),
                new EntityField('type_ua')->setTypeVarchar(64),
                new EntityField('district_ua')->setTypeVarchar(255),
                new EntityField('region_ua')->setTypeVarchar(255),
                new EntityField('search_text')->setTypeText(),
                new EntityField('points')->setTypeInt(11, false)->setDefault(0),
                ...$loaded(),
            ],
            BranchEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeVarchar(36, false),
                new EntityField('region_id')->setTypeVarchar(36, false)->setIndex(),
                new EntityField('city_id')->setTypeVarchar(36, false)->setIndex(),
                new EntityField('number')->setTypeVarchar(16, false),
                new EntityField('kind')->setTypeVarchar(16, false),
                new EntityField('type_code')->setTypeVarchar(16, false),
                new EntityField('name_ua')->setTypeVarchar(255, false),
                new EntityField('address_ua')->setTypeVarchar(255, false),
                new EntityField('zip')->setTypeVarchar(10),
                new EntityField('lat')->setTypeDecimal('10,7'),
                new EntityField('lng')->setTypeDecimal('10,7'),
                new EntityField('schedule_json')->setTypeText()->setNullable(),
                new EntityField('weight_place_max')->setTypeDecimal('8,2'),
                new EntityField('weight_total_max')->setTypeDecimal('8,2'),
                new EntityField('volume_place_max')->setTypeDecimal('10,4'),
                new EntityField('insurance_max')->setTypeDecimal('12,2'),
                new EntityField('length_max')->setTypeInt(11),
                new EntityField('width_max')->setTypeInt(11),
                new EntityField('height_max')->setTypeInt(11),
                new EntityField('phone_required')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('sending_only')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('receiving_only')->setTypeTinyInt(1, false)->setDefault(0),
                // EntityField ядра не має типу date; час завжди 00:00:00, бо BranchRow пише лише дату.
                new EntityField('inbound_from')->setTypeDatetime(),
                new EntityField('inbound_to')->setTypeDatetime(),
                new EntityField('overloaded')->setTypeTinyInt(1, false)->setDefault(0),
                ...$loaded(),
            ],
        ];
    }

    /**
     * Видалення модуля лишає його таблиці, а migrateEntityTable() — це голий CREATE TABLE:
     * повторне встановлення засмітило б лог повідомленнями "table already exists".
     *
     * @param array<class-string, list<EntityField>> $tables
     */
    protected function createMissingTables(array $tables): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $serviceLocator->getService(Database::class);
        $db->query($serviceLocator->getService(QueryFactory::class)->newSqlQuery()->setStatement('SHOW TABLES'));
        $existing = [];
        foreach ($db->results() as $row) {
            $existing[] = (string)current((array)$row);
        }

        $prefix = (string)$serviceLocator->getService(Config::class)->get('db_prefix');
        foreach (self::missingTables($tables, $existing, $prefix) as $entityClass => $fields) {
            $this->migrateEntityTable($entityClass, $fields);
        }
    }

    /**
     * @param array<class-string, list<EntityField>> $tables
     * @param list<string> $existing назви таблиць бази з префіксом
     * @return array<class-string, list<EntityField>>
     */
    public static function missingTables(array $tables, array $existing, string $prefix): array
    {
        // Ядро підставляє префікс таблиць замість початкового "__".
        return array_filter(
            $tables,
            static fn(string $entityClass): bool => !in_array($prefix . substr($entityClass::getTable(), 2), $existing, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function addDefaultGroups(): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var EntityFactory $entityFactory */
        $entityFactory = $serviceLocator->getService(EntityFactory::class);
        /** @var Languages $languages */
        $languages = $serviceLocator->getService(Languages::class);
        $groups = $entityFactory->get(GroupEntity::class);
        // Видалення модуля лишає його таблиці: повторне встановлення не повинне додати типові групи вдруге.
        if ($groups->count() > 0) {
            return;
        }
        $siteLanguages = $entityFactory->get(LanguagesEntity::class)->find();
        $initialLanguage = $languages->getLangId();

        foreach (DefaultGroups::rows() as $row) {
            $id = $groups->add([
                'position' => $row['position'],
                'enabled' => $row['enabled'],
                'kinds' => json_encode($row['kinds']),
                'name' => DefaultGroups::name($row, ''),
            ]);
            foreach ($siteLanguages as $language) {
                $languages->setLangId((int)$language->id);
                $groups->update($id, ['name' => DefaultGroups::name($row, (string)$language->label)]);
            }
        }
        $languages->setLangId($initialLanguage);
    }
}
