<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;

final class MassOrderSource implements MassOrders
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $lines,
        private readonly ParcelDrafts $drafts,
        private readonly Settings $settings,
    ) {
    }

    public function isOurs(int $orderId): bool
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);

        return is_object($order) && $this->lines->ownDelivery($order) !== null;
    }

    public function draft(int $orderId): ParcelDraft|string
    {
        return $this->drafts->forOrder($orderId);
    }

    public function config(): ParcelConfig
    {
        return ParcelConfig::fromSettings($this->settings->get(...));
    }
}
