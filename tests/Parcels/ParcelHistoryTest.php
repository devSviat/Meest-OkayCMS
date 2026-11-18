<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\ParcelHistory;
use Okay\Modules\Sviat\Meest\Parcels\TrackingEvent;
use PHPUnit\Framework\TestCase;

class ParcelHistoryTest extends TestCase
{
    private static function event(int $code, string $at, string $name = 'Подія', string $place = 'Львів'): TrackingEvent
    {
        return new TrackingEvent('711-2550636', $code, $at, $name, $place);
    }

    public function testNewestFiftyKept(): void
    {
        $events = [];
        for ($i = 0; $i < 60; $i++) {
            $events[] = self::event(606, date('Y-m-d H:i:s', strtotime('2026-11-01 00:00:00') + $i * 60));
        }

        $history = ParcelHistory::decode(ParcelHistory::encode(ParcelHistory::fromEvents($events)));

        self::assertCount(ParcelHistory::MAX_EVENTS, $history);
        self::assertSame('2026-11-01 00:10:00', $history[0]['at']);
        self::assertSame('2026-11-01 00:59:00', $history[49]['at']);
    }

    public function testEntryShape(): void
    {
        self::assertSame(
            [['code' => 1622, 'name' => 'Вручено', 'place' => 'Київ', 'at' => '2026-11-10 12:00:00']],
            ParcelHistory::fromEvents([self::event(1622, '2026-11-10 12:00:00', 'Вручено', 'Київ')]),
        );
    }

    public function testSameKeyNotAppended(): void
    {
        $history = ParcelHistory::fromEvents([self::event(606, '2026-11-08 09:00:00'), self::event(1100, '2026-11-08 12:00:00')]);

        self::assertSame($history, ParcelHistory::append($history, self::event(1100, '2026-11-08 12:00:00', 'Інша назва')));
        self::assertCount(3, ParcelHistory::append($history, self::event(1100, '2026-11-09 07:00:00')));
    }

    public function testOlderEventLandsInOrder(): void
    {
        $history = ParcelHistory::fromEvents([self::event(606, '2026-11-08 09:00:00'), self::event(1213, '2026-11-08 18:00:00')]);

        $merged = ParcelHistory::append($history, self::event(1100, '2026-11-08 12:00:00'));

        self::assertSame([606, 1100, 1213], array_column($merged, 'code'));
    }

    public function testCorruptJsonIsEmpty(): void
    {
        self::assertSame([], ParcelHistory::decode('{not json'));
        self::assertSame([], ParcelHistory::decode('{"a":1}'));
        self::assertSame([], ParcelHistory::decode(null));
        self::assertSame([], ParcelHistory::decode('[{"code":"x"},5,{"code":606,"name":"a","place":"b","at":"вчора"}]'));
    }

    public function testEmptyHistoryEncodesToNull(): void
    {
        self::assertNull(ParcelHistory::encode([]));
    }
}
