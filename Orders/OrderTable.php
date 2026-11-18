<?php

namespace Okay\Modules\Sviat\Meest\Orders;

/**
 * Чи є в базі всі таблиці модуля (Init::tables()): таблицю знімків замовлень (1.1.0) і таблицю посилок (1.2.0)
 * на вже встановленому модулі створює лише «Оновити».
 */
interface OrderTable
{
    public function exists(): bool;
}
