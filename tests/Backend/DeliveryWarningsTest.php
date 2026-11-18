<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/FixedOrderTable.php';

use Modules\Sviat\Meest\Support\FixedOrderTable;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyStatus;
use PHPUnit\Framework\TestCase;

class DeliveryWarningsTest extends TestCase
{
    private function warnings(?string $passedAt, bool $checked = true, ?FixedOrderTable $table = null): DeliveryWarnings
    {
        $state = new InMemoryState();
        if ($checked) {
            $result = new KeyCheckResult(KeyStatus::Ok, KeyStatus::Ok, null, new \DateTimeImmutable('@1800000000'), $passedAt);
            $state->set(KeyChecker::STATE, $result->toJson());
        }

        return new DeliveryWarnings($state, $table ?? new FixedOrderTable());
    }

    private static function delivery(int $paid, string $freeFrom): object
    {
        return (object)['id' => 1, 'module_id' => 75, 'paid' => $paid, 'free_from' => $freeFrom];
    }

    public function testNotCheckedLiveUntilFirstPass(): void
    {
        $delivery = self::delivery(1, '3000.00');

        self::assertSame(['not_checked_live'], $this->warnings(null, false)->for($delivery), 'no check yet');
        self::assertSame(['not_checked_live'], $this->warnings(null)->for($delivery), 'checked, never passed');
        self::assertSame([], $this->warnings('2026-11-16 14:05:00')->for($delivery));
    }

    public function testFreeForAnyCart(): void
    {
        $passed = $this->warnings('2026-11-16 14:05:00');

        self::assertSame(['free_for_any_cart'], $passed->for(self::delivery(1, '0.00')));
        self::assertSame([], $passed->for(self::delivery(1, '3000.00')), 'threshold set');
        self::assertSame([], $passed->for(self::delivery(0, '0.00')), 'free by design, not paid');
        self::assertSame(['free_for_any_cart', 'not_checked_live'], $this->warnings(null)->for(self::delivery(1, '0')));
    }

    /** 1.1.0 викладено, а «Оновити» модуля ще не натиснули: замовлення пішли б без знімка пункту. */
    public function testNeedsUpdateWhileOrderTableIsMissing(): void
    {
        $missing = new FixedOrderTable(false);
        $warnings = $this->warnings('2026-11-16 14:05:00', table: $missing);

        self::assertSame(['needs_update'], $warnings->for(self::delivery(1, '3000.00')));
        self::assertSame(['needs_update'], $warnings->for(self::delivery(1, '3000.00')));
        self::assertSame([], $this->warnings('2026-11-16 14:05:00', table: new FixedOrderTable(true))->for(self::delivery(1, '3000.00')));
    }
}
