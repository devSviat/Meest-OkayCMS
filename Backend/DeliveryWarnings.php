<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Meest\Orders\OrderTable;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/** Попередження на картці способу доставки модуля; ключі — частини мовних ключів `sviat__meest__warn_{ключ}_*`. */
final class DeliveryWarnings
{
    public const string FREE_FOR_ANY_CART = 'free_for_any_cart';
    public const string NOT_CHECKED_LIVE = 'not_checked_live';
    public const string NEEDS_UPDATE = 'needs_update';

    public function __construct(
        private readonly StateStorage $state,
        private readonly OrderTable $orderTable,
    ) {
    }

    /** @return list<string> */
    public function for(object $delivery): array
    {
        $warnings = [];
        if (!$this->orderTable->exists()) {
            $warnings[] = self::NEEDS_UPDATE;
        }
        if (self::freeForAnyCart($delivery)) {
            $warnings[] = self::FREE_FOR_ANY_CART;
        }
        if (KeyCheckResult::fromJson($this->state->get(KeyChecker::STATE))?->passedAt === null) {
            $warnings[] = self::NOT_CHECKED_LIVE;
        }

        return $warnings;
    }

    /**
     * Платний спосіб із порогом безкоштовної доставки 0 ядро робить безкоштовним для будь-якого кошика
     * (DeliveriesHelper::getCartDeliveriesList), тож ціна Meest ніколи не показується.
     */
    private static function freeForAnyCart(object $delivery): bool
    {
        return !empty($delivery->paid) && (float)($delivery->free_from ?? 0) <= 0;
    }
}
