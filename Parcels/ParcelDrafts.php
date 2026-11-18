<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderParcel;
use Okay\Modules\Sviat\Meest\Quote\Parcel;

/** Будує першу чернетку посилки замовлення. */
final class ParcelDrafts
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $deliveryLines,
        private readonly OrderDeliveryStore $snapshots,
        private readonly OrderParcel $parcel,
        private readonly UahTotal $uahTotal,
        private readonly Settings $settings,
    ) {
    }

    /** @return ParcelDraft|string чернетка або мовний ключ того, чого бракує замовленню */
    public function forOrder(int $orderId): ParcelDraft|string
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        if (!$order) {
            return 'sviat__meest__parcel_err_no_order';
        }
        if ($this->deliveryLines->ownDelivery($order) === null) {
            return 'sviat__meest__parcel_err_not_own';
        }
        $snapshot = $this->snapshots->forOrder($orderId);
        $branchId = $snapshot === null ? '' : (string)($snapshot->branch_id ?? '');
        // Пункт беремо з довідника, а не зі знімка: ліміти й `sending_only` змінюються після замовлення.
        $branch = $branchId !== '' ? $this->entityFactory->get(BranchEntity::class)->get($branchId) : null;
        if (!is_object($branch)) {
            return 'sviat__meest__parcel_err_no_point';
        }

        $config = ParcelConfig::fromSettings($this->settings->get(...));
        $metrics = $this->uahTotal->metrics($this->parcel->cart($order));
        if ($config->shipping->declareTotal && $metrics->totalUah === null) {
            return 'sviat__meest__parcel_err_no_uah';
        }
        $weight = Parcel::fromMetrics($metrics, $config->shipping)->weightKg;

        return new ParcelDraft(
            trim(trim((string)($order->last_name ?? '')) . ' ' . trim((string)($order->name ?? ''))),
            self::phone((string)($order->phone ?? '')),
            $branch,
            $weight,
            $config->shipping->lengthCm,
            $config->shipping->widthCm,
            $config->shipping->heightCm,
            Parcel::insurance($config->shipping, (float)$metrics->totalUah),
            $config->shipping->receiverPay,
            $config->shipping->payType,
            $config->shipping->description,
            str_replace('{order}', (string)$orderId, $config->notationTemplate),
        );
    }

    /** Нормалізований телефон, а коли він хибний, — як ввели, щоб форма показала помилку з тим, що набрано. */
    private static function phone(string $raw): string
    {
        return MeestPhone::normalize($raw) ?? $raw;
    }
}
