<?php

namespace Modules\Sviat\Meest\Cart;

use Okay\Modules\Sviat\Meest\Cart\WorkSchedule;
use PHPUnit\Framework\TestCase;

class WorkScheduleTest extends TestCase
{
    private static function day(string $day, ?string $from, ?string $to, ?string $breakFrom = null, ?string $breakTo = null): array
    {
        return ['day' => $day, 'from' => $from, 'to' => $to, 'break_from' => $breakFrom, 'break_to' => $breakTo];
    }

    public function testGroupsEqualDays(): void
    {
        $rows = [];
        foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт'] as $day) {
            $rows[] = self::day($day, '08:00', '20:00');
        }
        $rows[] = self::day('Сб', '09:00', '15:00');

        self::assertSame([
            ['from' => 0, 'to' => 4, 'hours' => '08:00–20:00', 'break' => null],
            ['from' => 5, 'to' => 5, 'hours' => '09:00–15:00', 'break' => null],
            ['from' => 6, 'to' => 6, 'hours' => null, 'break' => null],
        ], WorkSchedule::groups(json_encode($rows)));
    }

    public function testBreakShownSeparately(): void
    {
        $rows = [];
        foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт'] as $day) {
            $rows[] = self::day($day, '09:00', '18:00', '13:00', '14:00');
        }

        self::assertSame(['from' => 0, 'to' => 4, 'hours' => '09:00–18:00', 'break' => '13:00–14:00'], WorkSchedule::groups(json_encode($rows))[0]);
    }

    public function testDayNamesInOtherForms(): void
    {
        $rows = [self::day('Monday', '09:00:00', '18:00:00'), self::day('Вс', '10:00', '12:00'), self::day('3', '09:00', '18:00')];

        $groups = WorkSchedule::groups(json_encode($rows));

        self::assertSame('09:00–18:00', $groups[0]['hours']);
        self::assertSame(['from' => 2, 'to' => 2, 'hours' => '09:00–18:00', 'break' => null], $groups[2]);
        self::assertSame('10:00–12:00', $groups[array_key_last($groups)]['hours']);
    }

    public function testNoScheduleOrGarbageIsNull(): void
    {
        self::assertNull(WorkSchedule::groups(''));
        self::assertNull(WorkSchedule::groups('[]'));
        self::assertNull(WorkSchedule::groups('{oops'));
        self::assertNull(WorkSchedule::groups(json_encode([self::day('Ніколи', '08:00', '20:00')])), 'unknown day');
    }

    public function testAllDay(): void
    {
        $rows = [];
        foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'] as $day) {
            $rows[] = self::day($day, '00:00', '23:59');
        }

        self::assertSame([['allDay' => true]], WorkSchedule::groups(json_encode($rows)));
    }
}
