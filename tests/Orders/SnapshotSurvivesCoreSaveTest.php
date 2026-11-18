<?php

namespace Modules\Sviat\Meest\Orders;

require_once __DIR__ . '/../Support/OrderWorld.php';
require_once __DIR__ . '/../Support/RecordingInit.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/FixedOrderTable.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\Meest\Support\FixedOrderTable;
use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\Meest\Parcels\ParcelView;
use Modules\Sviat\Meest\Support\InMemoryState;
use Modules\Sviat\Meest\Support\OrderWorld;
use Modules\Sviat\Meest\Support\RecordingInit;
use Okay\Admin\Helpers\BackendOrdersHelper;
use Okay\Admin\Requests\BackendOrdersRequest;
use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Modules\Sviat\Meest\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Meest\Extenders\BackendExtender;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Meest\Orders\OrdersMassAction;
use PHPUnit\Framework\TestCase;

/**
 * Збереження замовлення ядром не перезаписує знімок пункту (баг 7 інвентаризації старого модуля):
 * знімок змінює лише редактор пункту.
 */
class SnapshotSurvivesCoreSaveTest extends TestCase
{
    use OrderWorld;

    /**
     * Хуки модуля на хелпері й запиті замовлень адмінки, і жоден з них не стоїть на шляху збереження.
     * buildFilter і findOrders — сторінка списку: масові дії модуля й бейджі.
     */
    private const array ALLOWED = [
        BackendOrdersHelper::class => ['findOrder', 'delete', 'buildFilter', 'findOrders'],
        BackendOrdersRequest::class => [],
    ];

    public function testCoreOrderSaveDoesNotTouchSnapshot(): void
    {
        foreach (RecordingInit::record() as [$kind, $expandable]) {
            if (!in_array($kind, ['chain', 'queue'], true) || !is_array($expandable) || !isset(self::ALLOWED[$expandable[0]])) {
                continue;
            }
            self::assertContains($expandable[1], self::ALLOWED[$expandable[0]], "{$expandable[0]}::{$expandable[1]}");
        }

        $this->ownOrder();
        $factory = $this->entityFactory();
        $design = $this->createStub(Design::class);
        // З посилкою блок показує картку: чернетка, яка читала б товари замовлення, тут не потрібна.
        $parcels = new InMemoryParcelRows();
        self::assertTrue($parcels->attach(77, ['parcel_id' => null, 'barcode' => '711-2550636']));
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturn(true);
        $extender = new BackendExtender(
            $design,
            $this->module(),
            new DeliveryWarnings(new InMemoryState(), new FixedOrderTable()),
            $factory,
            $this->createStub(BackendTranslations::class),
            new OrderDeliveryStore($factory),
            new \ReflectionClass(OrderRowsCleanup::class)->newInstanceWithoutConstructor(),
            $this->lines($factory),
            $parcels,
            new ParcelView($this->lines($factory), new OrderDeliveryStore($factory), $parcels, new \ReflectionClass(ParcelDrafts::class)->newInstanceWithoutConstructor(), new AuthFlags(new InMemoryState()), time(...)),
            new \ReflectionClass(Request::class)->newInstanceWithoutConstructor(),
            new \ReflectionClass(Response::class)->newInstanceWithoutConstructor(),
            new \ReflectionClass(OrdersMassAction::class)->newInstanceWithoutConstructor(),
            $modules,
        );
        $before = clone $this->snapshot;

        // Сторінка, яку ядро показує після збереження замовлення, читає знімок через findOrder.
        $extender->assignOrderDelivery($this->order);

        self::assertSame([], $this->snapshotWrites);
        self::assertEquals($before, $this->snapshot);
    }
}
