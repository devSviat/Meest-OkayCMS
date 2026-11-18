<?php

namespace Okay\Modules\Sviat\Meest\Orders;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Cart\CartSelection;
use Okay\Modules\Sviat\Meest\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Meest\Quote\DeliveryQuote;

final class OrderDeliveryStore
{
    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * Назви копіюються з рядків довідника: замовлення зберігає те, що обрали, навіть якщо довідник зміниться.
     *
     * @param array{price?: ?float, price_source?: ?string, estimated_date?: ?string}|null $pricing ключ, якого немає, лишає збережене значення
     */
    public function save(int $orderId, CartSelection $selection, ?array $pricing = null): void
    {
        if (!$selection->applies() || $selection->delivery === null) {
            return;
        }
        $entity = $this->entityFactory->get(OrderDeliveryEntity::class);
        $row = ($pricing ?? []) + self::row($orderId, $selection);
        $existing = $entity->findOne(['order_id' => $orderId]);
        $existing ? $entity->update((int)$existing->id, $row) : $entity->add($row + ['created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> лише з рядків довідника, ніколи з форми */
    public static function row(int $orderId, CartSelection $selection): array
    {
        $city = $selection->city;
        $branch = $selection->branch;

        return [
            'order_id' => $orderId,
            'delivery_id' => (int)$selection->delivery?->id,
            'city_id' => (string)$city?->id,
            'city_name' => self::cityName($city),
            'branch_id' => (string)$branch?->id,
            'branch_number' => mb_substr((string)($branch->number ?? ''), 0, 16),
            'branch_kind' => mb_substr((string)($branch->kind ?? ''), 0, 16),
            'branch_title' => mb_substr((string)($branch->name_ua ?? ''), 0, 255),
            'branch_address' => mb_substr((string)($branch->address_ua ?? ''), 0, 255),
        ];
    }

    /**
     * Ціна й дата для знімка. Дата — лише справжня дата Y-m-d (S1): вільного тексту в замовленні немає.
     *
     * @return array{price: ?float, price_source: ?string, estimated_date: ?string}
     */
    public static function pricing(?DeliveryQuote $quote, ?float $price, ?string $source): array
    {
        $date = $quote?->estimatedDate;
        $valid = is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);

        return ['price' => $price, 'price_source' => $source, 'estimated_date' => $valid ? $date : null];
    }

    /** Тип, назва, район і область: у тезок однакова назва, тож замовлення зберігає, котрий із них. */
    public static function cityName(?object $city): string
    {
        if ($city === null) {
            return '';
        }
        $name = trim((string)($city->type_ua ?? '') . ' ' . (string)($city->name_ua ?? ''));
        $parts = array_filter([$name, (string)($city->district_ua ?? ''), (string)($city->region_ua ?? '')], static fn(string $part): bool => $part !== '');

        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /** Ціна доставки, яку менеджер задав в адмінці. */
    public function setPrice(int $orderId, float $price, string $source): void
    {
        $entity = $this->entityFactory->get(OrderDeliveryEntity::class);
        $row = $entity->findOne(['order_id' => $orderId]);
        if (is_object($row)) {
            $entity->update((int)$row->id, ['price' => $price, 'price_source' => $source]);
        }
    }

    public function forOrder(int $orderId): ?object
    {
        return $this->entityFactory->get(OrderDeliveryEntity::class)->findOne(['order_id' => $orderId]) ?: null;
    }

    /** Останній вибір покупця, який знову пропонується в кошику. */
    public function lastForUser(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }
        $orderIds = array_keys($this->entityFactory->get(OrdersEntity::class)->mappedBy('id')->cols(['id'])->find(['user_id' => $userId, 'limit' => 20]));
        if ($orderIds === []) {
            return null;
        }
        $rows = $this->entityFactory->get(OrderDeliveryEntity::class)->find(['order_id' => $orderIds, 'limit' => 1]);

        return $rows ? reset($rows) : null;
    }
}
