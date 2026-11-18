<?php

namespace Modules\Sviat\Meest\Extenders;

require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/FixedOrderTable.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\Meest\Support\FixedOrderTable;
use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Modules\Sviat\Meest\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Meest\Extenders\BackendExtender;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Meest\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Meest\Parcels\ParcelRows;
use Okay\Modules\Sviat\Meest\Parcels\ParcelView;
use PHPUnit\Framework\TestCase;

class BackendExtenderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $assigned = [];

    private function extender(int $moduleId = 75, bool $active = true, ?ParcelRows $rows = null): BackendExtender
    {
        $this->assigned = [];
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value): void {
            $this->assigned[$name] = $value;
        });
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn($moduleId);

        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn($vendor, $name): bool => $active && $vendor === 'Sviat' && $name === 'Meest');

        $blank = static fn(string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();

        return new BackendExtender(
            $design,
            $module,
            new DeliveryWarnings(new InMemoryState(), new FixedOrderTable()),
            $this->createStub(EntityFactory::class),
            $this->createStub(BackendTranslations::class),
            $blank(OrderDeliveryStore::class),
            $blank(OrderRowsCleanup::class),
            $blank(OrderDeliveryLines::class),
            $rows ?? new InMemoryParcelRows(),
            $blank(ParcelView::class),
            $blank(Request::class),
            $blank(Response::class),
            $blank(OrdersMassAction::class),
            $modules,
        );
    }

    public function testDeliveryWarningOnlyForOwnMethods(): void
    {
        $own = (object)['module_id' => 75, 'paid' => 1, 'free_from' => '0'];
        $this->extender()->deliveryWarning($own);
        self::assertSame(['free_for_any_cart', 'not_checked_live'], $this->assigned['meest_delivery_warnings']);

        $this->extender(moduleId: 12)->deliveryWarning($own);
        self::assertSame([], $this->assigned['meest_delivery_warnings']);

        $this->extender(moduleId: 0)->deliveryWarning((object)['module_id' => 0, 'paid' => 1, 'free_from' => '0']);
        self::assertSame([], $this->assigned['meest_delivery_warnings'], 'module id unknown: nothing is ours');
    }

    public function testListRowsCarryBarcodeGroupAndEvent(): void
    {
        $created = BackendExtender::listParcel((object)['order_id' => 1, 'barcode' => '711-2550636', 'state' => 'created', 'status_code' => '1213', 'status_group' => null, 'event_name' => 'Прибула']);
        self::assertSame(['barcode' => '711-2550636', 'group' => 'arrived', 'statusKey' => 'sviat__meest__parcel_group_arrived', 'eventName' => 'Прибула'], $created);

        foreach (['pending', 'uncertain'] as $state) {
            self::assertSame(
                ['barcode' => null, 'group' => 'unsettled', 'statusKey' => 'sviat__meest__list_unsettled', 'eventName' => null],
                BackendExtender::listParcel((object)['order_id' => 2, 'barcode' => null, 'state' => $state, 'status_code' => null, 'status_group' => null, 'event_name' => null]),
            );
        }
    }

    /** Адмінка запускає init() і вимкнених модулів: вимкнений Meest не виконує надісланої дії й не читає посилок. */
    public function testDisabledModuleIgnoresMassActionAndBadges(): void
    {
        $rows = $this->createMock(ParcelRows::class);
        $rows->expects(self::never())->method('forOrders');
        $extender = $this->extender(active: false, rows: $rows);
        [$server, $post, $session] = [$_SERVER, $_POST, $_SESSION ?? null];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => OrdersMassAction::CREATE, 'check' => ['1']];
        $_SESSION = [];
        try {
            self::assertSame(['page' => 1], $extender->listFilter(['page' => 1]));
            self::assertSame([], $_SESSION);
        } finally {
            [$_SERVER, $_POST] = [$server, $post];
            $_SESSION = $session;
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $extender->listFilter([]);
        } finally {
            $_SERVER = $server;
        }
        self::assertArrayNotHasKey('meest_mass_result', $this->assigned);

        $order = (object)['id' => 1];
        $extender->listOrders([$order]);
        self::assertObjectNotHasProperty('meest_list_parcel', $order);
    }
}
