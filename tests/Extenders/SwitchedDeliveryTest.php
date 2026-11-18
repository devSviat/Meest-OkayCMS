<?php

namespace Modules\Sviat\Meest\Extenders;

require_once __DIR__ . '/../Support/OrderWorld.php';
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
use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Modules\Sviat\Meest\Backend\DeliveryWarnings;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Extenders\BackendExtender;
use Okay\Modules\Sviat\Meest\Extenders\FrontExtender;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderParcel;
use Okay\Modules\Sviat\Meest\Orders\OrderPointChange;
use Okay\Modules\Sviat\Meest\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Meest\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use PHPUnit\Framework\TestCase;

/**
 * Менеджер змінив доставку замовлення на спосіб іншого модуля: знімок Meest лишається в базі,
 * але ні сторінка замовлення, ні листи, ні адмінка його не показують.
 */
class SwitchedDeliveryTest extends TestCase
{
    use OrderWorld;

    /** @var array<string, mixed> */
    private array $assigned = [];

    private function design(): Design
    {
        $design = self::createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value) use ($design) {
            $this->assigned[$name] = $value;

            return $design;
        });

        return $design;
    }

    private static function blank(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    private function backend(bool $active = true): BackendExtender
    {
        $factory = $this->entityFactory();
        // З посилкою блок показує картку: чернетка, яка читала б товари замовлення, тут не потрібна.
        $parcels = new InMemoryParcelRows();
        self::assertTrue($parcels->attach(77, ['parcel_id' => null, 'barcode' => '711-2550636']));
        $modules = self::createStub(Modules::class);
        $modules->method('isActiveModule')->willReturn($active);

        return new BackendExtender(
            $this->design(),
            $this->module(),
            new DeliveryWarnings(new InMemoryState(), new FixedOrderTable()),
            $factory,
            self::createStub(BackendTranslations::class),
            new OrderDeliveryStore($factory),
            self::blank(OrderRowsCleanup::class),
            $this->lines($factory),
            $parcels,
            new ParcelView($this->lines($factory), new OrderDeliveryStore($factory), $parcels, self::blank(ParcelDrafts::class), new AuthFlags(new InMemoryState()), time(...)),
            self::blank(Request::class),
            self::blank(Response::class),
            self::blank(OrdersMassAction::class),
            $modules,
        );
    }

    private function front(): FrontExtender
    {
        $factory = $this->entityFactory();
        $settings = self::createStub(Settings::class);
        $settings->method('get')->willReturn('');
        $front = self::blank(FrontExtender::class);
        foreach (['design' => $this->design(), 'settings' => $settings, 'orderLines' => $this->lines($factory)] as $property => $value) {
            new \ReflectionProperty(FrontExtender::class, $property)->setValue($front, $value);
        }

        return $front;
    }

    /** OrdersHelper::getOrderPaymentMethodsList — сторінка замовлення покупця. */
    public function testOrderPageHidesPointOfSwitchedDelivery(): void
    {
        $this->switchedOrder();
        $this->front()->assignOrderDelivery(null, $this->order);

        self::assertArrayHasKey('meest_order_delivery', $this->assigned);
        self::assertNull($this->assigned['meest_order_delivery']);
    }

    /** NotifyHelper::finalEmailOrderUser / finalEmailOrderAdmin — той самий extender із замовленням листа. */
    public function testEmailsHidePointOfSwitchedDelivery(): void
    {
        $this->switchedOrder();
        $front = $this->front();
        foreach (['user', 'admin'] as $email) {
            $this->assigned = [];
            $front->assignOrderDelivery(null, $this->order);
            self::assertArrayHasKey('meest_order_delivery', $this->assigned, $email);
            self::assertNull($this->assigned['meest_order_delivery'], $email);
        }
    }

    public function testControlOwnDeliveryStillShowsPoint(): void
    {
        $this->ownOrder();
        $this->backend()->assignOrderDelivery($this->order);

        self::assertSame('м. Вигадане, Перший, Тестова', $this->assigned['meest_order_delivery']['city']);
        self::assertSame(self::BRANCH_ID, $this->assigned['meest_order_point']['branchId']);

        $this->assigned = [];
        $this->front()->assignOrderDelivery(null, $this->order);
        self::assertSame('м. Вигадане, Перший, Тестова', $this->assigned['meest_order_delivery']['city']);
    }

    /** Адмінка виконує init() і вимкнених модулів: вимкнений Meest не показує картки посилки з її кнопками. */
    public function testDisabledModuleShowsNoParcelCard(): void
    {
        $this->ownOrder();
        $this->backend()->assignOrderDelivery($this->order);
        self::assertSame('card', $this->assigned['meest_parcel']['mode']);

        $this->assigned = [];
        $this->backend(active: false)->assignOrderDelivery($this->order);
        self::assertArrayHasKey('meest_parcel', $this->assigned);
        self::assertNull($this->assigned['meest_parcel']);
    }

    public function testAdminHidesPointAndEditorOfSwitchedDelivery(): void
    {
        $this->switchedOrder();
        $this->backend()->assignOrderDelivery($this->order);

        self::assertArrayHasKey('meest_order_delivery', $this->assigned);
        self::assertNull($this->assigned['meest_order_delivery']);
        self::assertNull($this->assigned['meest_order_point']);
    }

    /** Редактор пункту: збережений пункт замовлення з чужою доставкою не переоцінюється й не змінюється. */
    public function testEditorRefusesSwitchedOrder(): void
    {
        $this->switchedOrder();
        $factory = $this->entityFactory();
        $change = new OrderPointChange(
            $factory,
            $this->module(),
            self::blank(GroupsStore::class),
            new OrderDeliveryStore($factory),
            self::blank(OrderParcel::class),
            self::blank(CartQuotes::class),
            $this->lines($factory),
            self::blank(\Okay\Core\Settings::class),
            new InMemoryParcelRows(),
        );

        $applied = $change->applyPrice(77);
        self::assertFalse($applied->ok);
        self::assertSame('order_point_foreign', $applied->error);

        $saved = $change->save(77, ['city_id' => self::CITY_ID, 'branch_id' => self::BRANCH_ID]);
        self::assertFalse($saved->ok);
        self::assertSame('order_point_foreign', $saved->error);
        self::assertSame([], $this->orderUpdates);
        self::assertSame([], $this->snapshotWrites);
    }
}
