<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/** Що масовому створенню треба знати про замовлення. */
interface MassOrders
{
    /** Замовлення існує й доставляється цим модулем. */
    public function isOurs(int $orderId): bool;

    /** @return ParcelDraft|string чернетка або мовний ключ того, чого бракує замовленню */
    public function draft(int $orderId): ParcelDraft|string;

    /** Відправник і договір, з якими будуються чернетки цього запуску. */
    public function config(): ParcelConfig;
}
