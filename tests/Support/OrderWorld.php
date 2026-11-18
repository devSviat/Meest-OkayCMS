<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;

/**
 * Для TestCase: замовлення, його знімок пункту й спосіб доставки — без бази.
 * Записи в замовлення лишаються в $orderUpdates, записи знімка — у $snapshotWrites.
 */
trait OrderWorld
{
    private const int MODULE = 81;
    private const int OWN_DELIVERY = 6;
    private const int FOREIGN_DELIVERY = 9;
    private const string CITY_ID = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string BRANCH_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

    private object $order;
    private ?object $snapshot = null;
    /** @var list<array{0:int, 1:array}> */
    private array $orderUpdates = [];
    /** @var list<array{0:string, 1:array}> */
    private array $snapshotWrites = [];

    private function switchedOrder(): void
    {
        $this->order = self::order(self::FOREIGN_DELIVERY);
        $this->snapshot = self::snapshot();
    }

    private function ownOrder(): void
    {
        $this->order = self::order(self::OWN_DELIVERY);
        $this->snapshot = self::snapshot();
    }

    private static function order(int $deliveryId): object
    {
        return (object)['id' => 77, 'delivery_id' => $deliveryId, 'payment_method_id' => 3, 'delivery_price' => 60.0, 'total_price' => 1060.0, 'separate_delivery' => 0, 'paid' => 0];
    }

    private static function snapshot(): object
    {
        return (object)[
            'id' => 1, 'order_id' => 77, 'delivery_id' => self::OWN_DELIVERY, 'city_id' => self::CITY_ID, 'city_name' => 'м. Вигадане, Перший, Тестова',
            'branch_id' => self::BRANCH_ID, 'branch_number' => '1', 'branch_kind' => 'branch', 'branch_title' => 'Відділення №1', 'branch_address' => 'вул. Лісова 1',
            'price' => '60.00', 'price_source' => 'api', 'estimated_date' => '2026-11-20 00:00:00', 'created_at' => '2026-11-17 10:00:00',
        ];
    }

    private function entityFactory(): EntityFactory
    {
        $deliveries = self::createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturnCallback(static fn($id) => match ((int)$id) {
            self::OWN_DELIVERY => (object)['id' => self::OWN_DELIVERY, 'module_id' => self::MODULE, 'hide_front_delivery_price' => 0, 'free_from' => 0, 'separate_payment' => 0],
            self::FOREIGN_DELIVERY => (object)['id' => self::FOREIGN_DELIVERY, 'module_id' => 12, 'hide_front_delivery_price' => 0, 'free_from' => 0, 'separate_payment' => 0],
            default => false,
        });
        $snapshots = self::createStub(OrderDeliveryEntity::class);
        $snapshots->method('findOne')->willReturnCallback(fn() => $this->snapshot ?? false);
        $snapshots->method('add')->willReturnCallback(function ($row) {
            $this->snapshotWrites[] = ['add', (array)$row];

            return 1;
        });
        $snapshots->method('update')->willReturnCallback(function ($id, $row) {
            $this->snapshotWrites[] = ['update', (array)$row];

            return true;
        });
        $orders = self::createStub(OrdersEntity::class);
        $orders->method('get')->willReturnCallback(fn() => $this->order);
        $orders->method('update')->willReturnCallback(function ($id, $data) {
            $this->orderUpdates[] = [(int)$id, (array)$data];

            return true;
        });
        $currencies = self::createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object)['sign' => 'грн']);

        $factory = self::createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            DeliveriesEntity::class => $deliveries,
            OrderDeliveryEntity::class => $snapshots,
            OrdersEntity::class => $orders,
            CurrenciesEntity::class => $currencies,
        });

        return $factory;
    }

    private function module(): Module
    {
        $module = self::createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);

        return $module;
    }

    private function lines(?EntityFactory $factory = null): OrderDeliveryLines
    {
        $factory ??= $this->entityFactory();

        return new OrderDeliveryLines($factory, $this->module(), new OrderDeliveryStore($factory));
    }
}
