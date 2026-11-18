<?php

namespace Modules\Sviat\Meest\Quote;

use Okay\Modules\Sviat\Meest\Quote\SendingDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SendingDateTest extends TestCase
{
    public static function moments(): iterable
    {
        yield 'before cutoff — today' => ['2026-11-17 15:59', '16:00', '17.11.2026'];
        yield 'at cutoff — tomorrow' => ['2026-11-17 16:00', '16:00', '18.11.2026'];
        yield 'after cutoff — tomorrow' => ['2026-11-17 22:10', '16:00', '18.11.2026'];
        yield 'year end' => ['2026-12-31 18:00', '16:00', '01.01.2027'];
        yield 'early cutoff' => ['2026-11-17 08:59', '09:00', '17.11.2026'];
    }

    #[DataProvider('moments')]
    public function testCutoffDecidesTodayOrTomorrow(string $now, string $cutoff, string $expected): void
    {
        $at = new \DateTimeImmutable($now, new \DateTimeZone('Europe/Kyiv'));

        self::assertSame($expected, SendingDate::for($at->getTimestamp(), $cutoff));
    }

    public function testKyivTimeNotServerTime(): void
    {
        // 14:30 UTC — це 16:30 у Києві взимку: відправка вже завтра.
        $utc = new \DateTimeImmutable('2026-11-17 14:30', new \DateTimeZone('UTC'));

        self::assertSame('18.11.2026', SendingDate::for($utc->getTimestamp(), '16:00'));
    }
}
