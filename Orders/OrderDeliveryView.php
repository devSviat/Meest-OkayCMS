<?php

namespace Okay\Modules\Sviat\Meest\Orders;

/** Збережений вибір замовлення як рядки тексту — для сторінок замовлення (покупця й адмінки) та обох листів замовлення. */
final class OrderDeliveryView
{
    /**
     * Читає лише знімок замовлення, ніколи довідник: назви мають лишитися тими, що обрав покупець.
     *
     * @param float $orderDeliveryPrice поточна ціна доставки замовлення: менеджер міг задати її після оформлення
     * @return array{city:string, point:string, estimatedDate:?string, priceUnconfirmed:bool}
     */
    public static function lines(object $row, float $orderDeliveryPrice): array
    {
        $title = (string)($row->branch_title ?? '');
        $address = (string)($row->branch_address ?? '');
        // Коротка назва Meest часто вже містить адресу.
        $point = array_filter([$title, $address !== '' && mb_stripos($title, $address) === false ? $address : ''], static fn(string $part): bool => $part !== '');
        $date = (string)($row->estimated_date ?? '');

        return [
            'city' => (string)($row->city_name ?? ''),
            'point' => implode(', ', $point),
            'estimatedDate' => preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 ? substr($date, 0, 10) : null,
            // Meest не дала ціни під час оформлення: доставку не нараховано, її вказує менеджер.
            'priceUnconfirmed' => ($row->price_source ?? null) === 'fallback' && !($orderDeliveryPrice > 0),
        ];
    }
}
