<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Де зберігаються посилки замовлень: одна на замовлення.
 *
 * Колонки рядка — ParcelEntity; `details` — JSON ParcelDetails. Дати — `Y-m-d H:i:s` у поясі застосунку.
 */
interface ParcelRows
{
    public function forOrder(int $orderId): ?object;

    /**
     * Рядки сторінки списку замовлень одним запитом, лише з колонками рядка списку.
     *
     * @param list<int> $orderIds
     * @return array<int, object> за id замовлення
     */
    public function forOrders(array $orderIds): array;

    /** Замовлення, якому належить штрихкод, без урахування дефісів (Barcode::key). */
    public function orderOfBarcode(string $barcode): ?int;

    public function orderOfParcelId(string $parcelId): ?int;

    /**
     * Займає замовлення до запиту до Meest: унікальний order_id пропускає лише один із двох одночасних запитів.
     *
     * @return ?string `parcelNumber` цієї спроби (`{order_id}-{id}`); null, коли замовлення вже має рядок або резерв не записався
     */
    public function reserve(int $orderId, string $now): ?string;

    /**
     * Перетворює резерв чи невідомий рядок на посилку. False, коли запис не вдався.
     *
     * @param array<string, mixed> $values колонки ParcelEntity
     */
    public function complete(int $orderId, array $values): bool;

    /**
     * Прив'язує посилку, створену деінде: новий рядок або резерв чи невідомий рядок замовлення.
     * False, коли замовлення має посилку чи штрихкод уже зайнятий.
     *
     * @param array<string, mixed> $values колонки ParcelEntity; `created_at` діє лише для нового рядка
     */
    public function attach(int $orderId, array $values): bool;

    /** Запит до Meest пішов без відповіді: замовлення чекає на пошук чи прив'язку. */
    public function markUncertain(int $orderId): void;

    /** Знімає резерв; рядок посилки лишається. */
    public function release(int $orderId): void;

    public function remove(int $orderId): void;

    /**
     * Рядки за штрихкодами без урахування дефісів (Barcode::key).
     *
     * @param list<string> $barcodes
     * @return array<string, object> за збереженим штрихкодом рядка
     */
    public function byBarcodes(array $barcodes): array;

    /**
     * Нові значення стану посилки (подія, група, історія, час перевірки); резерв не змінюється.
     *
     * @param array<string, mixed> $values колонки ParcelEntity
     */
    public function saveEvent(int $orderId, array $values): void;

    public function markChecked(int $orderId, string $at): void;

    /**
     * Незавершені посилки, про які давно нічого не чути: ні перевірки, ні події з `$silentBefore`.
     * Спершу ті, що найдовше не перевірялися.
     *
     * @return list<object>
     */
    public function dueForSafety(int $limit, string $createdSince, string $silentBefore): array;

    /** Дата створення найстарішої незавершеної посилки, створеної не раніше `$createdSince`. */
    public function oldestUnfinishedCreatedAt(string $createdSince): ?string;
}
