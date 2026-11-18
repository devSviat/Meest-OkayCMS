<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryView;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/**
 * Що показує блок посилки на сторінці замовлення: форму нової, розбір невідомого створення або картку наявної.
 * `actions` — кнопки, які шаблон малює; ParcelAdmin усе одно перевіряє кожну дію сам.
 */
final class ParcelView
{
    /** @param \Closure(): int $now той самий годинник, що й у ParcelService: картка й дії однаково бачать застарілий резерв */
    public function __construct(
        private readonly OrderDeliveryLines $lines,
        private readonly OrderDeliveryStore $snapshots,
        private readonly ParcelRows $rows,
        private readonly ParcelDrafts $drafts,
        private readonly AuthFlags $auth,
        private readonly \Closure $now,
    ) {
    }

    /** @return ?array null — блок не показується: доставка не Meest і посилки немає */
    public function forOrder(object $order): ?array
    {
        $orderId = (int)($order->id ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        return self::build(
            $orderId,
            $this->rows->forOrder($orderId),
            $this->lines->ownDelivery($order) !== null,
            fn(): ParcelDraft|string => $this->drafts->forOrder($orderId),
            $this->auth->items(),
            ($this->now)(),
            $this->snapshots->forOrder($orderId),
        );
    }

    /**
     * @param \Closure(): (ParcelDraft|string) $draft чернетка потрібна лише формі, а будується з товарів замовлення
     * @param list<array{api: string, reason: string}> $auth AuthFlags::items()
     * @param ?object $snapshot рядок OrderDeliveryEntity замовлення
     */
    public static function build(int $orderId, ?object $row, bool $own, \Closure $draft, array $auth, int $now, ?object $snapshot = null): ?array
    {
        $view = self::state($orderId, $row, $own, $draft, $now, $snapshot);
        if ($view === null) {
            return null;
        }

        // Smarty 5 не викликає in_array() без реєстрації: шаблон питає `can.<дія>`.
        return $view + ['can' => array_fill_keys($view['actions'], true), 'auth' => $auth];
    }

    /** @param \Closure(): (ParcelDraft|string) $draft */
    private static function state(int $orderId, ?object $row, bool $own, \Closure $draft, int $now, ?object $snapshot): ?array
    {
        // Після зміни способу доставки посилка старого пункту лишається видимою: її треба видалити чи відв'язати.
        if ($row === null && !$own) {
            return null;
        }
        $view = [
            'orderId' => $orderId,
            'mode' => 'form',
            'actions' => [],
            'card' => null,
            'form' => null,
            'issues' => [],
            'refusalKey' => null,
        ];

        if (PendingParcel::needsCheck($row, $now)) {
            return ['mode' => 'check', 'actions' => ['lookup', 'attach', 'unlink']] + $view;
        }
        if (PendingParcel::running($row, $now)) {
            return ['mode' => 'pending', 'refusalKey' => 'sviat__meest__parcel_err_pending'] + $view;
        }
        if ($row !== null) {
            $actions = ['sticker', 'refresh', ...(ParcelService::deletable($row) ? ['remove'] : []), 'unlink'];

            return ['mode' => 'card', 'card' => ParcelCard::fromRow($row, self::point($row, $snapshot)), 'actions' => $actions] + $view;
        }

        $built = $draft();
        if (is_string($built)) {
            return ['mode' => 'refusal', 'refusalKey' => $built, 'actions' => ['attach']] + $view;
        }

        return ['form' => self::form($built), 'issues' => $built->errors(), 'actions' => ['create', 'attach']] + $view;
    }

    /** Пункт зі знімка замовлення — лише коли посилку створено саме на нього: менеджер міг змінити пункт згодом. */
    private static function point(object $row, ?object $snapshot): ?string
    {
        $branchId = ParcelDetails::decode($row->details ?? null)['branch_id'] ?? null;
        if (!is_string($branchId) || $snapshot === null || strcasecmp($branchId, (string)($snapshot->branch_id ?? '')) !== 0) {
            return null;
        }
        $point = OrderDeliveryView::lines($snapshot, 0.0)['point'];

        return $point !== '' ? $point : null;
    }

    /** @return array<string, mixed> */
    private static function form(ParcelDraft $draft): array
    {
        $point = array_filter([trim((string)($draft->branch->name_ua ?? '')), trim((string)($draft->branch->address_ua ?? ''))], static fn(string $part): bool => $part !== '');

        return [
            'receiverName' => $draft->receiverName,
            'receiverPhone' => $draft->receiverPhone,
            'point' => implode(', ', $point),
            'weight' => rtrim(rtrim(number_format($draft->weightKg, 3, '.', ''), '0'), '.'),
            'length' => (string)$draft->lengthCm,
            'width' => (string)$draft->widthCm,
            'height' => (string)$draft->heightCm,
            'declared' => number_format($draft->declaredValue, 2, '.', ''),
            'payer' => $draft->receiverPay ? 'receiver' : 'sender',
            'payType' => $draft->payType,
            'payTypes' => ShippingConfig::PAY_TYPES,
            'description' => $draft->description,
            'notation' => $draft->notation,
        ];
    }
}
