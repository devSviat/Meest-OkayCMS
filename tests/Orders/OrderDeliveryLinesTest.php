<?php

namespace Modules\Sviat\Meest\Orders;

require_once __DIR__ . '/../Support/OrderWorld.php';

use Modules\Sviat\Meest\Support\OrderWorld;
use PHPUnit\Framework\TestCase;

/** Знімок пункту лишається в базі, коли менеджер змінив доставку замовлення на іншу, але не показується. */
class OrderDeliveryLinesTest extends TestCase
{
    use OrderWorld;

    public function testOwnDeliveryShowsThePoint(): void
    {
        $this->ownOrder();

        $lines = $this->lines()->forOrder($this->order);

        self::assertSame('м. Вигадане, Перший, Тестова', $lines['city']);
        self::assertSame('Відділення №1, вул. Лісова 1', $lines['point']);
        self::assertSame('2026-11-20', $lines['estimatedDate']);
        self::assertFalse($lines['priceUnconfirmed']);
    }

    public function testSwitchedDeliveryShowsNothing(): void
    {
        $this->switchedOrder();

        self::assertNull($this->lines()->forOrder($this->order));
        self::assertNull($this->lines()->ownDelivery($this->order));
    }

    public function testOwnDeliveryWithoutSnapshotShowsNothing(): void
    {
        $this->ownOrder();
        $this->snapshot = null;

        self::assertNull($this->lines()->forOrder($this->order));
        self::assertNotNull($this->lines()->ownDelivery($this->order));
    }
}
