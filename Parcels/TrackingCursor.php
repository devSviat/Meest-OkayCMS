<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Вікна `trackingByPeriod`: наздоганяння від курсора й перечитування останніх годин.
 * Курсор рухає лише успішне вікно наздоганяння (advance); перечитування його не торкається.
 */
final class TrackingCursor
{
    public const string STATE = 'tracking_cursor';

    /** Подія з'являється у вікні не одразу, тому курсор відстає від «зараз». */
    public const int LAG = 1800;
    public const int WINDOW = 7200;
    public const int MAX_CATCH_UP = 24;
    public const int REREAD = 21600;
    public const int FIRST_RUN_BACK = 604800;

    private const string MEEST_ZONE = 'Europe/Kyiv';

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly StateStorage $state,
        private readonly \Closure $now,
    ) {
    }

    /**
     * @return list<array{from: int, to: int, advances: bool}>
     */
    public function windows(?string $oldestUnfinishedCreatedAt): array
    {
        $now = ($this->now)();
        $edge = $now - self::LAG;
        $windows = [];

        $from = $this->start($oldestUnfinishedCreatedAt, $now);
        for ($i = 0; $i < self::MAX_CATCH_UP && $from < $edge; $i++) {
            $to = min(self::windowEnd($from), $edge);
            $windows[] = ['from' => $from, 'to' => $to, 'advances' => true];
            $from = $to;
        }

        for ($from = $edge - self::REREAD; $from < $edge; $from = $to) {
            $to = min(self::windowEnd($from), $edge);
            $windows[] = ['from' => $from, 'to' => $to, 'advances' => false];
        }

        return $windows;
    }

    /**
     * Вікно Meest — до 2 год: і за реальним часом, і за настінним часом Києва, який Meest бачить у шляху.
     * Перед переходом на літній час настінна година, якої немає, зсуває кінець вперед, тож вікно скорочується.
     */
    public static function windowEnd(int $from): int
    {
        $to = $from + self::WINDOW;
        $excess = self::wallSeconds($to) - self::wallSeconds($from) - self::WINDOW;

        return $excess > 0 ? $to - $excess : $to;
    }

    public static function path(int $from, int $to): string
    {
        return 'trackingByPeriod/' . self::formatKyiv($from) . '/' . self::formatKyiv($to);
    }

    public function advance(int $to): void
    {
        $current = (int)$this->state->get(self::STATE);
        if ($to > $current) {
            $this->state->set(self::STATE, (string)$to);
        }
    }

    private function start(?string $oldestUnfinishedCreatedAt, int $now): int
    {
        $stored = $this->state->get(self::STATE);
        if ($stored !== null && ctype_digit($stored)) {
            return (int)$stored;
        }
        $oldest = $oldestUnfinishedCreatedAt === null ? false : strtotime($oldestUnfinishedCreatedAt);

        return $oldest === false ? $now : max($oldest, $now - self::FIRST_RUN_BACK);
    }

    /** Настінний час Києва як мітка UTC: різниця двох таких міток — розрив між датами шляху. */
    private static function wallSeconds(int $at): int
    {
        $wall = new \DateTimeImmutable('@' . $at)->setTimezone(new \DateTimeZone(self::MEEST_ZONE))->format('Y-m-d H:i:s');

        return new \DateTimeImmutable($wall, new \DateTimeZone('UTC'))->getTimestamp();
    }

    private static function formatKyiv(int $at): string
    {
        $wall = new \DateTimeImmutable('@' . $at)->setTimezone(new \DateTimeZone(self::MEEST_ZONE))->format('Y-m-d H:i:s');

        return rawurlencode($wall);
    }
}
