<?php

namespace Okay\Modules\Sviat\Meest\Quote;

final readonly class DeliveryQuote
{
    public function __construct(
        public ?float $price,
        /** `estimatedDeliveryDate` як Y-m-d; null, коли Meest її не дала або дата нерозбірна. */
        public ?string $estimatedDate,
        /** api | none | fallback: none — ціну не запитували; fallback — запит не вдався. */
        public string $source,
        /** null | unreachable | rejected | rate_limited | not_configured | no_sender | no_uah | busy */
        public ?string $problem,
    ) {
    }

    /**
     * Meest запитали, і вона не дала ціни: з покупця не беруть плату за доставку, менеджер
     * повідомить її пізніше. "busy" сюди не належить: оформлення замовлення все одно рахує ціну.
     */
    public function unpriced(): bool
    {
        return $this->price === null && $this->source === 'fallback' && $this->problem !== 'busy';
    }
}
