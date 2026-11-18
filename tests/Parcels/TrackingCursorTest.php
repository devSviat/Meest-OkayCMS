<?php

namespace Modules\Sviat\Meest\Parcels;

use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Parcels\TrackingCursor;
use PHPUnit\Framework\TestCase;

class TrackingCursorTest extends TestCase
{
    private InMemoryState $state;

    protected function setUp(): void
    {
        $this->state = new InMemoryState();
    }

    private static function ts(string $utc): int
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))->getTimestamp();
    }

    /** Різниця настінного часу Києва між кінцями вікна, як їх бачить Meest у шляху. */
    private static function wallSpan(int $from, int $to): int
    {
        $wall = static fn(int $at): int => new \DateTimeImmutable(
            new \DateTimeImmutable('@' . $at)->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d H:i:s'),
            new \DateTimeZone('UTC'),
        )->getTimestamp();

        return $wall($to) - $wall($from);
    }

    private function cursor(string $nowUtc): TrackingCursor
    {
        $now = self::ts($nowUtc);

        return new TrackingCursor($this->state, static fn(): int => $now);
    }

    public function testPathEncodedInKyiv(): void
    {
        $from = self::ts('2026-07-15T09:00:00Z');

        self::assertSame(
            'trackingByPeriod/2026-07-15%2012%3A00%3A00/2026-07-15%2014%3A00%3A00',
            TrackingCursor::path($from, TrackingCursor::windowEnd($from)),
        );
    }

    public function testSpringForwardWindow(): void
    {
        $from = self::ts('2026-03-29T00:30:00Z');

        self::assertSame(self::ts('2026-03-29T01:30:00Z'), TrackingCursor::windowEnd($from));
        self::assertSame(
            'trackingByPeriod/2026-03-29%2002%3A30%3A00/2026-03-29%2004%3A30%3A00',
            TrackingCursor::path($from, TrackingCursor::windowEnd($from)),
        );
    }

    public function testFallBackWindow(): void
    {
        $from = self::ts('2026-10-25T00:00:00Z');

        self::assertSame($from + 7200, TrackingCursor::windowEnd($from));
        self::assertLessThanOrEqual(7200, self::wallSpan($from, TrackingCursor::windowEnd($from)));
    }

    public function testWindowBeforeSpringGapStaysWithinTwoWallHours(): void
    {
        $from = self::ts('2026-03-28T23:30:00Z');
        $to = TrackingCursor::windowEnd($from);

        self::assertGreaterThan($from, $to);
        self::assertLessThanOrEqual(7200, $to - $from);
        self::assertLessThanOrEqual(7200, self::wallSpan($from, $to));
    }

    public function testFirstRunNotEarlierThanSevenDays(): void
    {
        $cursor = $this->cursor('2026-11-17 12:00:00');

        $windows = $cursor->windows('2026-09-01 00:00:00');

        self::assertSame(self::ts('2026-11-10 12:00:00'), $windows[0]['from']);
    }

    public function testFirstRunStartsAtOldestUnfinished(): void
    {
        $windows = $this->cursor('2026-11-17 12:00:00')->windows(date('Y-m-d H:i:s', self::ts('2026-11-17 08:00:00')));

        self::assertSame(self::ts('2026-11-17 08:00:00'), $windows[0]['from']);
    }

    public function testNoUnfinishedAndNoCursorGivesOnlyReread(): void
    {
        $windows = $this->cursor('2026-11-17 12:00:00')->windows(null);

        self::assertNotSame([], $windows);
        self::assertSame([false], array_values(array_unique(array_column($windows, 'advances'))));
    }

    public function testCatchUpCappedAtTwentyFour(): void
    {
        $now = '2026-11-17 12:00:00';
        $this->state->set(TrackingCursor::STATE, (string)(self::ts($now) - 3 * 86400));

        $windows = $this->cursor($now)->windows(null);
        $catchUp = array_values(array_filter($windows, static fn(array $w): bool => $w['advances']));

        self::assertCount(TrackingCursor::MAX_CATCH_UP, $catchUp);
        self::assertSame(self::ts($now) - 3 * 86400, $catchUp[0]['from']);
        self::assertSame($catchUp[0]['to'], $catchUp[1]['from']);
        self::assertLessThanOrEqual(self::ts($now) - TrackingCursor::LAG, $catchUp[23]['to']);
    }

    public function testCatchUpEndsAtLaggedNow(): void
    {
        $now = self::ts('2026-11-17 12:00:00');
        $this->state->set(TrackingCursor::STATE, (string)($now - 3600));

        $catchUp = array_values(array_filter($this->cursor('2026-11-17 12:00:00')->windows(null), static fn(array $w): bool => $w['advances']));

        self::assertSame($now - TrackingCursor::LAG, $catchUp[array_key_last($catchUp)]['to']);
        self::assertSame($now - 3600, $catchUp[0]['from']);
    }

    public function testRereadCoversSixHours(): void
    {
        $now = self::ts('2026-11-17 12:00:00');
        $this->state->set(TrackingCursor::STATE, (string)($now - TrackingCursor::LAG));

        $windows = $this->cursor('2026-11-17 12:00:00')->windows(null);

        self::assertCount(3, $windows);
        self::assertSame($now - TrackingCursor::LAG - TrackingCursor::REREAD, $windows[0]['from']);
        self::assertSame($now - TrackingCursor::LAG, $windows[2]['to']);
        foreach ($windows as $window) {
            self::assertFalse($window['advances']);
            self::assertLessThanOrEqual(7200, $window['to'] - $window['from']);
        }
    }

    public function testAdvanceNeverMovesBack(): void
    {
        $cursor = $this->cursor('2026-11-17 12:00:00');

        $cursor->advance(2000);
        $cursor->advance(1000);

        self::assertSame('2000', $this->state->get(TrackingCursor::STATE));
    }
}
