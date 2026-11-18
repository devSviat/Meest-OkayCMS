<?php

namespace Okay\Modules\Sviat\Meest\Orders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Entities\DeliveriesEntity;

/**
 * Пункт видачі замовлення для сторінок замовлення, листів і редактора адмінки. Коли менеджер змінив
 * доставку на спосіб іншого модуля, знімок лишається в базі (повернення доставки поверне й пункт),
 * але не показується і не змінюється.
 */
final class OrderDeliveryLines
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly OrderDeliveryStore $snapshots,
    ) {
    }

    /** Поточний спосіб доставки замовлення, якщо він цього модуля; інакше null. */
    public function ownDelivery(object $order): ?object
    {
        $deliveryId = (int)($order->delivery_id ?? 0);
        $delivery = $deliveryId > 0 ? $this->entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);

        return $delivery && $moduleId > 0 && (int)($delivery->module_id ?? 0) === $moduleId ? $delivery : null;
    }

    /** @return ?array{city:string, point:string, estimatedDate:?string, priceUnconfirmed:bool} null — пункту немає або доставка не цього модуля */
    public function forOrder(object $order): ?array
    {
        if (empty($order->id)) {
            return null;
        }
        $row = $this->ownDelivery($order) ? $this->snapshots->forOrder((int)$order->id) : null;

        return $row === null ? null : OrderDeliveryView::lines($row, (float)($order->delivery_price ?? 0));
    }
}
