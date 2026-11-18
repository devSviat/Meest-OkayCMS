<?php

namespace Okay\Modules\Sviat\Meest\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;

/**
 * Посилка Meest для замовлення, одна на замовлення (`order_id`, `parcel_id`, `barcode`, `parcel_number` унікальні).
 * Рядок з'являється до запиту до Meest як резерв: див. PendingParcel.
 */
class ParcelEntity extends Entity
{
    protected static $fields = [
        'id',
        'order_id',
        'parcel_id',
        'barcode',
        'parcel_number',
        'contract_id',
        'state',
        'status_code',
        'status_group',
        'event_name',
        'event_at',
        'arrived_at',
        'delivery_price',
        'declared_value',
        'receiver_pay',
        'pay_type',
        'details',
        'history',
        'created_at',
        'reserved_at',
        'checked_at',
    ];

    /**
     * Лише колонки, які entity знає: ядро пише в INSERT/UPDATE усі ключі, і невідома колонка провалила б увесь запис.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function knownColumns(array $values): array
    {
        return array_intersect_key($values, array_flip(static::getAllDefaultFields()));
    }

    /** Створені посилки зі штрихкодом без фінальної групи, створені не раніше `since`. */
    protected function filter__unfinished(string $since): void
    {
        $alias = $this->getTableAlias();
        $final = implode(',', array_map(static fn(string $g): string => "'{$g}'", ParcelStatus::FINAL));
        $this->select->where("{$alias}.state = '" . PendingParcel::CREATED . "'")
            ->where("{$alias}.barcode IS NOT NULL AND {$alias}.barcode <> ''")
            ->where("{$alias}.created_at >= :meest_unfinished_since")
            // Явний IS NULL: NOT IN із NULL дало б NULL, і рядок без групи випав би з вибірки.
            ->where("({$alias}.status_group IS NULL OR {$alias}.status_group NOT IN ({$final}))")
            ->bindValue('meest_unfinished_since', $since);
    }

    /**
     * Штрихкоди без дефісів (Barcode::key). REPLACE не бере індекс `barcode`: таблиця сканується, зате рядок,
     * збережений з дефісом, знаходиться за подією без нього.
     *
     * @param list<string> $keys
     */
    protected function filter__barcode_key(array $keys): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("REPLACE({$alias}.barcode, '-', '') IN (:meest_barcode_keys)")
            ->bindValue('meest_barcode_keys', $keys);
    }

    /** Ні перевірки, ні події з `before`. */
    protected function filter__silent_before(string $before): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("({$alias}.checked_at IS NULL OR {$alias}.checked_at < :meest_silent_checked)")
            ->where("({$alias}.event_at IS NULL OR {$alias}.event_at < :meest_silent_event)")
            ->bindValue('meest_silent_checked', $before)
            ->bindValue('meest_silent_event', $before);
    }

    /** `stale`: спершу ті, що найдовше не перевірялися; `oldest`: за датою створення. */
    protected function customOrder($order = null, array $orderFields = [], array $additionalData = [])
    {
        if ($order === 'stale') {
            $orderFields = ['checked_at', 'id'];
        } elseif ($order === 'oldest') {
            $orderFields = ['created_at', 'id'];
        }

        return parent::customOrder($order, $orderFields, $additionalData);
    }

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__meest__parcels';
    protected static $tableAlias = 'meprc';
}
