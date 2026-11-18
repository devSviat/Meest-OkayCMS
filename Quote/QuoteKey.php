<?php

namespace Okay\Modules\Sviat\Meest\Quote;

/** Визначає, для чого зроблено розрахунок ціни; будь-яка зміна кошика дає новий ключ, тож застаріла ціна не показується. */
final class QuoteKey
{
    /** Збільшується, коли змінюється зміст закешованого розрахунку ціни. */
    private const string FORMAT = 'v1';

    /** @param string $sendingDate від неї залежить орієнтовна дата доставки, тож після години відправки потрібен новий розрахунок */
    public static function for(int $deliveryId, string $branchId, object $cart, string $sendingDate = ''): string
    {
        $lines = [];
        foreach ((array)($cart->purchases ?? []) as $purchase) {
            // Вага варіанта: адміністратор може змінити її, поки ціна лежить у кеші сесії.
            $variant = $purchase->variant ?? null;
            $lines[] = (int)($purchase->variant_id ?? $variant->id ?? 0) . ':' . (int)($purchase->amount ?? 0) . ':' . (float)($variant->weight ?? 0);
        }
        sort($lines);

        return sha1(implode('|', [self::FORMAT, $deliveryId, $branchId, implode(',', $lines), number_format((float)($cart->total_price ?? 0), 2, '.', ''), $sendingDate]));
    }
}
