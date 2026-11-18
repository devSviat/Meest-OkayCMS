<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Modules\Sviat\Meest\Entities\ParcelEntity;
use Okay\Modules\Sviat\Meest\Parcels\Barcode;
use Okay\Modules\Sviat\Meest\Parcels\ParcelRows;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;

/** Повторює унікальні order_id, parcel_id, barcode і parcel_number таблиці: дубль не записується, як і в базі. */
final class InMemoryParcelRows implements ParcelRows
{
    private const array UNIQUE = ['order_id', 'parcel_id', 'barcode', 'parcel_number'];

    /** @var array<int, object> за id рядка */
    public array $rows = [];
    /** База відхилила запис після того, як Meest створила посилку. */
    public bool $failComplete = false;
    /** Вставка резерву пройшла, а номер спроби не записався: рядок прибрано, резерву немає. */
    public bool $failReserve = false;
    /** Спроби вставки резерву: у базі кожна зайва впирається в UNIQUE і пише ERROR 1062 у лог. */
    public int $reserveCalls = 0;
    /** Інший запит зайняв замовлення між перевіркою рядка й вставкою резерву. */
    public bool $concurrentReserve = false;
    private int $nextId = 1;

    public function forOrder(int $orderId): ?object
    {
        foreach ($this->rows as $row) {
            if ($row->order_id === $orderId) {
                return $row;
            }
        }

        return null;
    }

    public function forOrders(array $orderIds): array
    {
        $found = [];
        foreach ($orderIds as $orderId) {
            $row = $this->forOrder($orderId);
            if ($row !== null) {
                $found[$orderId] = $row;
            }
        }

        return $found;
    }

    public function orderOfBarcode(string $barcode): ?int
    {
        foreach ($this->rows as $row) {
            if ($row->barcode !== null && Barcode::key($row->barcode) === Barcode::key($barcode)) {
                return $row->order_id;
            }
        }

        return null;
    }

    public function orderOfParcelId(string $parcelId): ?int
    {
        foreach ($this->rows as $row) {
            if (strtolower((string)($row->parcel_id ?? '')) === strtolower($parcelId)) {
                return $row->order_id;
            }
        }

        return null;
    }

    public function reserve(int $orderId, string $now): ?string
    {
        $this->reserveCalls++;
        if ($this->concurrentReserve) {
            $this->concurrentReserve = false;
            $this->reserve($orderId, $now);
        }
        $id = $this->insert(['order_id' => $orderId, 'state' => PendingParcel::PENDING, 'created_at' => $now, 'reserved_at' => $now]);
        if ($id !== null && $this->failReserve) {
            unset($this->rows[$id]);

            return null;
        }
        if ($id === null) {
            return null;
        }
        $this->rows[$id]->parcel_number = "{$orderId}-{$id}";

        return $this->rows[$id]->parcel_number;
    }

    public function complete(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        if ($this->failComplete || !PendingParcel::is($row)) {
            return false;
        }

        return $this->write($row, $values + ['state' => PendingParcel::CREATED, 'reserved_at' => null, 'status_group' => 'created']);
    }

    public function attach(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        $values += ['state' => PendingParcel::CREATED, 'reserved_at' => null];
        if ($row === null) {
            return $this->insert($values + ['order_id' => $orderId]) !== null;
        }
        unset($values['created_at']);

        return PendingParcel::is($row) && $this->write($row, $values);
    }

    public function markUncertain(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            $row->state = PendingParcel::UNCERTAIN;
        }
    }

    public function release(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            unset($this->rows[$row->id]);
        }
    }

    public function remove(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null) {
            unset($this->rows[$row->id]);
        }
    }

    public function byBarcodes(array $barcodes): array
    {
        $keys = array_map(Barcode::key(...), $barcodes);
        $found = [];
        foreach ($this->rows as $row) {
            if ($row->barcode !== null && in_array(Barcode::key($row->barcode), $keys, true)) {
                $found[$row->barcode] = $row;
            }
        }

        return $found;
    }

    public function saveEvent(int $orderId, array $values): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null && !PendingParcel::is($row)) {
            $this->write($row, $values);
        }
    }

    public function markChecked(int $orderId, string $at): void
    {
        $this->saveEvent($orderId, ['checked_at' => $at]);
    }

    public function dueForSafety(int $limit, string $createdSince, string $silentBefore): array
    {
        $due = array_filter($this->rows, static fn(object $row): bool => self::unfinished($row, $createdSince)
            && ($row->checked_at === null || $row->checked_at < $silentBefore)
            && ($row->event_at === null || $row->event_at < $silentBefore));
        usort($due, static fn(object $a, object $b): int => [$a->checked_at ?? '', $a->id] <=> [$b->checked_at ?? '', $b->id]);

        return array_slice(array_values($due), 0, $limit);
    }

    public function oldestUnfinishedCreatedAt(string $createdSince): ?string
    {
        $dates = array_map(
            static fn(object $row): string => $row->created_at,
            array_filter($this->rows, static fn(object $row): bool => self::unfinished($row, $createdSince)),
        );

        return $dates === [] ? null : min($dates);
    }

    private static function unfinished(object $row, string $createdSince): bool
    {
        return $row->state === PendingParcel::CREATED
            && $row->barcode !== null
            && $row->created_at >= $createdSince
            && !in_array($row->status_group, ParcelStatus::FINAL, true);
    }

    /** @param array<string, mixed> $values */
    private function insert(array $values): ?int
    {
        $row = (object)(['id' => $this->nextId] + array_fill_keys(ParcelEntity::getFields(), null));
        foreach (ParcelEntity::knownColumns($values) as $field => $value) {
            $row->{$field} = $value;
        }
        if ($this->duplicates($row)) {
            return null;
        }
        $this->rows[$this->nextId] = $row;

        return $this->nextId++;
    }

    /** @param array<string, mixed> $values */
    private function write(object $row, array $values): bool
    {
        $changed = clone $row;
        foreach (ParcelEntity::knownColumns($values) as $field => $value) {
            $changed->{$field} = $value;
        }
        if ($this->duplicates($changed)) {
            return false;
        }
        foreach ((array)$changed as $field => $value) {
            $row->{$field} = $value;
        }

        return true;
    }

    private function duplicates(object $candidate): bool
    {
        foreach ($this->rows as $row) {
            if ($row->id === $candidate->id) {
                continue;
            }
            foreach (self::UNIQUE as $column) {
                if ($candidate->{$column} !== null && $row->{$column} === $candidate->{$column}) {
                    return true;
                }
            }
        }

        return false;
    }
}
