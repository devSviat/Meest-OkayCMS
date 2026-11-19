<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;

final class ParcelStore implements ParcelRows
{
    /** Що читає рядок посилки в списку замовлень: без JSON знімка й історії. */
    public const array LIST_COLUMNS = ['order_id', 'barcode', 'state', 'status_code', 'status_group', 'event_name'];

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /** @phpstan-impure кожен виклик перечитує рядок: перевірка запису порівнює стан до й після */
    public function forOrder(int $orderId): ?object
    {
        $row = $this->entity()->findOne(['order_id' => $orderId]);

        return is_object($row) ? $row : null;
    }

    public function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds), static fn(int $id): bool => $id > 0)));
        if ($orderIds === []) {
            return [];
        }
        $rows = [];
        foreach ($this->entity()->cols(self::LIST_COLUMNS)->find(['order_id' => $orderIds, 'limit' => count($orderIds)]) as $row) {
            $rows[(int)$row->order_id] = $row;
        }

        return $rows;
    }

    public function orderOfBarcode(string $barcode): ?int
    {
        $row = $this->entity()->findOne(['barcode_key' => [Barcode::key($barcode)]]);

        return is_object($row) ? (int)$row->order_id : null;
    }

    public function orderOfParcelId(string $parcelId): ?int
    {
        $row = $this->entity()->findOne(['parcel_id' => $parcelId]);

        return is_object($row) ? (int)$row->order_id : null;
    }

    public function reserve(int $orderId, string $now): ?string
    {
        // Ядро ковтає помилку дубліката ключа: другий запит дізнається, що замовлення зайняте, з false.
        $id = $this->entity()->add([
            'order_id' => $orderId,
            'state' => PendingParcel::PENDING,
            'created_at' => $now,
            'reserved_at' => $now,
        ]);
        // На стоковому ядрі невдала вставка може віддати id попередньої вставки з'єднання: вірить лише перечитаний рядок.
        $row = $id ? $this->forOrder($orderId) : null;
        if ($row === null || (int)$row->id !== (int)$id || $row->parcel_number !== null || !PendingParcel::is($row)) {
            return null;
        }
        // id відомий лише після вставки, тож номер пишеться другим кроком; NULL у UNIQUE колонці дублем не є.
        $number = "{$orderId}-{$row->id}";
        $this->entity()->update((int)$row->id, ['parcel_number' => $number]);
        // Невдалий UPDATE ядро теж ковтає. Запит до Meest ще не йшов, тож резерв без номера безпечно знімається.
        $numbered = $this->forOrder($orderId);
        if ($numbered === null || $numbered->parcel_number !== $number) {
            $this->entity()->delete([(int)$row->id]);

            return null;
        }

        return $number;
    }

    public function complete(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        if (!PendingParcel::is($row)) {
            return false;
        }
        $values = ['state' => PendingParcel::CREATED, 'reserved_at' => null] + $values + ['status_group' => 'created'];

        return $this->updateChecked((int)$row->id, $orderId, $values);
    }

    public function attach(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        $values = ['state' => PendingParcel::CREATED, 'reserved_at' => null] + $values;
        if ($row === null) {
            $id = $this->entity()->add(ParcelEntity::knownColumns($values + ['order_id' => $orderId]));
            // Як і в reserve(): на стоковому ядрі невдала вставка може віддати id попередньої вставки з'єднання,
            // тож успіх видно лише з перечитаного рядка замовлення.
            $saved = $id ? $this->forOrder($orderId) : null;

            return $saved !== null
                && $saved->state === PendingParcel::CREATED
                && $saved->barcode === ($values['barcode'] ?? null);
        }
        if (!PendingParcel::is($row)) {
            return false;
        }
        // Невідомий рядок стає посилкою, а дата створення лишається датою резерву.
        unset($values['created_at']);

        return $this->updateChecked((int)$row->id, $orderId, $values);
    }

    public function markUncertain(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            $this->entity()->update((int)$row->id, ['state' => PendingParcel::UNCERTAIN]);
        }
    }

    public function release(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function remove(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function byBarcodes(array $barcodes): array
    {
        $keys = array_values(array_unique(array_filter(array_map(Barcode::key(...), $barcodes), static fn(string $k): bool => $k !== '')));
        if ($keys === []) {
            return [];
        }
        $rows = [];
        // Ліміт за кількістю ключів: штрихкод унікальний, а з дефісом і без — щонайбільше два рядки на ключ.
        foreach ($this->entity()->find(['barcode_key' => $keys, 'limit' => 2 * count($keys)]) as $row) {
            $rows[(string)$row->barcode] = $row;
        }

        return $rows;
    }

    public function saveEvent(int $orderId, array $values): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null && !PendingParcel::is($row)) {
            $this->entity()->update((int)$row->id, ParcelEntity::knownColumns($values));
        }
    }

    public function markChecked(int $orderId, string $at): void
    {
        $this->saveEvent($orderId, ['checked_at' => $at]);
    }

    public function dueForSafety(int $limit, string $createdSince, string $silentBefore): array
    {
        return array_values($this->entity()->order('stale')->find([
            'unfinished' => $createdSince,
            'silent_before' => $silentBefore,
            'limit' => $limit,
        ]));
    }

    public function oldestUnfinishedCreatedAt(string $createdSince): ?string
    {
        $createdAt = $this->entity()->cols(['created_at'])->order('oldest')->findOne(['unfinished' => $createdSince]);

        return is_string($createdAt) && $createdAt !== '' ? $createdAt : null;
    }

    /**
     * Дубль унікального ключа update() теж ковтає: успіх видно лише з перечитаного рядка.
     *
     * @param array<string, mixed> $values
     */
    private function updateChecked(int $id, int $orderId, array $values): bool
    {
        $values = ParcelEntity::knownColumns($values);
        $this->entity()->update($id, $values);
        $saved = $this->forOrder($orderId);

        return $saved !== null
            && $saved->state === PendingParcel::CREATED
            && ($values['barcode'] ?? null) === $saved->barcode
            && ($values['parcel_id'] ?? null) === $saved->parcel_id;
    }

    private function entity(): ParcelEntity
    {
        return $this->entityFactory->get(ParcelEntity::class);
    }
}
