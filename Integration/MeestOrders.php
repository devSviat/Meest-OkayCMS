<?php

namespace Okay\Modules\Sviat\Meest\Integration;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;
use Okay\Modules\Sviat\Meest\Parcels\ParcelTracking;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;

/**
 * Контракт лише на читання для інших модулів. Усе поза Integration\ може змінитися без попередження;
 * цей клас і його value objects — ні.
 *
 * Використовуйте його лише тоді, коли модуль активний: class_exists(MeestOrders::class) і
 * Modules::isActiveModule('Sviat', 'Meest'); вимкнений модуль не реєструє сервісів.
 * Штрихкоди віддаються у формі Meest (можуть містити дефіс), час — у поясі застосунку.
 */
final class MeestOrders
{
    private const array PARCEL_COLUMNS = ['order_id', 'barcode', 'state', 'status_group', 'event_at', 'arrived_at'];
    private const array AT_BRANCH = ['arrived', 'storage'];

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, MeestParcel> за id замовлення; посилки без штрихкоду (ще не створені) пропущено
     */
    public function parcels(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        return $this->build($this->parcelEntity()->find(['order_id' => $orderIds]));
    }

    /** @return array<int, MeestParcel> посилки, які Meest ще везе (не фінальні, штрихкод відомий) */
    public function unfinished(): array
    {
        $since = date('Y-m-d H:i:s', time() - ParcelTracking::TRACK_FOR_DAYS * 86400);

        return $this->build($this->parcelEntity()->find(['unfinished' => $since]));
    }

    /** Null, якщо менеджер змінив доставку замовлення: знімок описує вже не його. */
    public function addressOf(int $orderId): ?MeestAddress
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->cols(['id', 'delivery_id'])->get($orderId);
        $delivery = $this->entityFactory->get(OrderDeliveryEntity::class)->findOne(['order_id' => $orderId]);
        if (!$order || !$delivery || (int)$delivery->delivery_id !== (int)$order->delivery_id) {
            return null;
        }

        return self::address($delivery);
    }

    /** Повний підзапит, а не назва таблиці: споживачі не мають знати її колонок. */
    public static function withBarcodeSql(): string
    {
        return "SELECT order_id FROM " . ParcelEntity::getTable()
            . " WHERE state = '" . PendingParcel::CREATED . "' AND barcode IS NOT NULL AND barcode <> ''";
    }

    public static function parcel(object $row): ?MeestParcel
    {
        $barcode = trim((string)($row->barcode ?? ''));
        if (($row->state ?? null) !== PendingParcel::CREATED || $barcode === '') {
            return null;
        }
        $group = ($row->status_group ?? null) !== null ? (string)$row->status_group : null;
        $arrivedAt = in_array($group, self::AT_BRANCH, true) ? self::date($row->arrived_at ?? null) : null;

        return new MeestParcel((int)$row->order_id, $barcode, $group, self::date($row->event_at ?? null), $arrivedAt);
    }

    public static function address(?object $delivery): ?MeestAddress
    {
        if ($delivery === null) {
            return null;
        }
        $text = static fn(string $name): string => trim((string)($delivery->$name ?? ''));
        // city_name містить через кому район і область; адресі потрібні лише тип і назва.
        $city = trim(explode(', ', $text('city_name'))[0]);
        $line = implode(', ', array_filter([$text('branch_title'), $text('branch_address')], static fn(string $p): bool => $p !== ''));

        return new MeestAddress($city, $line, $text('branch_number'));
    }

    private function parcelEntity(): ParcelEntity
    {
        return $this->entityFactory->get(ParcelEntity::class)->noLimit()->cols(self::PARCEL_COLUMNS);
    }

    /** @param iterable<object> $rows */
    private function build(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $parcel = self::parcel($row);
            if ($parcel !== null) {
                $result[$parcel->orderId] = $parcel;
            }
        }

        return $result;
    }

    private static function date(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }
}
