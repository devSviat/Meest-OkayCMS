<?php

namespace Modules\Sviat\Meest\Parcels;

require_once __DIR__ . '/../Support/OrderWorld.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Modules\Sviat\Meest\Support\InMemoryState;
use Modules\Sviat\Meest\Support\OrderWorld;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\Meest\Parcels\ParcelView;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

/** Картка й ParcelService мусять бачити той самий час, інакше картка показує «створюється» там, де сервіс уже чекає на пошук. */
class ParcelViewClockTest extends TestCase
{
    use OrderWorld;

    public function testStaleReservationJudgedByTheInjectedClock(): void
    {
        $this->ownOrder();
        $reservedAt = '2030-03-17 10:00:00';
        $rows = new InMemoryParcelRows();
        self::assertNotNull($rows->reserve(77, $reservedAt));
        $now = strtotime($reservedAt) + PendingParcel::STALE_SECONDS + 1;
        $factory = $this->entityFactory();
        $view = new ParcelView(
            $this->lines($factory),
            new OrderDeliveryStore($factory),
            $rows,
            new \ReflectionClass(ParcelDrafts::class)->newInstanceWithoutConstructor(),
            new AuthFlags(new InMemoryState()),
            static fn(): int => $now,
        );

        self::assertSame('check', $view->forOrder($this->order)['mode']);
    }
}
