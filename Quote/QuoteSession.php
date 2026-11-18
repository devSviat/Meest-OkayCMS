<?php

namespace Okay\Modules\Sviat\Meest\Quote;

/**
 * Розрахунки ціни й останній вибір покупця, що зберігаються в PHP-сесії (`$bag` — місце модуля
 * в ній). Лише прості масиви: сесія не повинна тримати об'єкти модуля, форма яких може змінитися.
 */
final class QuoteSession
{
    private const int TTL = 3600;
    private const int MAX_QUOTES = 10;

    /**
     * @param ?int $retryUnreachableAfter секунди, після яких відповідь "unreachable" вважається відсутньою, тож
     *     кошик знову запитує Meest; оформлення замовлення передає null і лишає те, що показав кошик.
     */
    public static function get(array $bag, string $key, int $now, ?int $retryUnreachableAfter = null): ?DeliveryQuote
    {
        $row = $bag['quotes'][$key] ?? null;
        if (!is_array($row) || !is_int($row['at'] ?? null) || $now - $row['at'] >= self::TTL) {
            return null;
        }
        if ($retryUnreachableAfter !== null && ($row['problem'] ?? null) === 'unreachable' && $now - $row['at'] >= $retryUnreachableAfter) {
            return null;
        }

        return new DeliveryQuote(
            is_numeric($row['price'] ?? null) ? (float)$row['price'] : null,
            is_string($row['date'] ?? null) ? $row['date'] : null,
            (string)($row['source'] ?? 'fallback'),
            is_string($row['problem'] ?? null) ? $row['problem'] : null,
        );
    }

    public static function put(array &$bag, string $key, DeliveryQuote $quote, int $now): void
    {
        $quotes = is_array($bag['quotes'] ?? null) ? $bag['quotes'] : [];
        unset($quotes[$key]);
        $quotes[$key] = ['price' => $quote->price, 'date' => $quote->estimatedDate, 'source' => $quote->source, 'problem' => $quote->problem, 'at' => $now];
        $bag['quotes'] = array_slice($quotes, -self::MAX_QUOTES, null, true);
    }

    public static function choose(array &$bag, int $deliveryId, string $branchId): void
    {
        $chosen = is_array($bag['chosen'] ?? null) ? $bag['chosen'] : [];
        $chosen[$deliveryId] = $branchId;
        $bag['chosen'] = $chosen;
    }

    /** UUID пункту, обраного для способу в цій сесії. */
    public static function chosen(array $bag, int $deliveryId): ?string
    {
        $branchId = is_array($bag['chosen'] ?? null) ? ($bag['chosen'][$deliveryId] ?? null) : null;

        return is_string($branchId) && $branchId !== '' ? $branchId : null;
    }
}
