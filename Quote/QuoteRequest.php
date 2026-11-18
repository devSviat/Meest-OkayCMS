<?php

namespace Okay\Modules\Sviat\Meest\Quote;

use Okay\Modules\Sviat\Meest\Api\RequestBudget;

final readonly class QuoteRequest
{
    public function __construct(
        public ShippingConfig $config,
        /** `branchID` пункту видачі, уже звірений з таблицею пунктів. */
        public string $branchId,
        public Parcel $parcel,
        /** Оголошена цінність, грн (Parcel::insurance). */
        public float $insurance,
        public string $slot = RequestBudget::CART,
        /** `dd.mm.yyyy`; null — Meest рахує від сьогодні. */
        public ?string $sendingDate = null,
    ) {
    }
}
