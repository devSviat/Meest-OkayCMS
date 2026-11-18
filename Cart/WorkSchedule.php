<?php

namespace Okay\Modules\Sviat\Meest\Cart;

/**
 * Тиждень пункту так, як його показує кошик: дні з однаковими годинами в одній групі ("Пн–Пт 08:00–20:00").
 * День тижня, а не "сьогодні": посилку забирають через кілька днів після оформлення замовлення.
 */
final class WorkSchedule
{
    /**
     * Формат `branchWorkTime[].day` вживу ще не звірено: приймаються скорочення українською,
     * російською, англійською й номер дня 1–7 (1 — понеділок).
     */
    private const array DAYS = [
        'пн' => 0, 'вт' => 1, 'ср' => 2, 'чт' => 3, 'пт' => 4, 'сб' => 5, 'нд' => 6, 'вс' => 6,
        'пон' => 0, 'вів' => 1, 'сер' => 2, 'чет' => 3, "п'я" => 4, 'суб' => 5, 'нед' => 6, 'вос' => 6,
        'mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6,
    ];

    /**
     * @param string $json `schedule_json` з довідника: [{day, from, to, break_from, break_to}]
     * @return list<array{from:int, to:int, hours:?string, break:?string}>|list<array{allDay:true}>|null дні 0 = понеділок … 6 = неділя;
     *         hours null — вихідний; null, коли розкладу немає або його не вдається прочитати
     */
    public static function groups(string $json): ?array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $pairs = array_fill(0, 7, null);
        $breaks = array_fill(0, 7, null);
        $valid = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $day = self::dayIndex($row['day'] ?? null);
            $from = self::time($row['from'] ?? null);
            $to = self::time($row['to'] ?? null);
            if ($day === null || $from === null || $to === null) {
                continue;
            }
            $pairs[$day] = [$from, $to];
            $breakFrom = self::time($row['break_from'] ?? null);
            $breakTo = self::time($row['break_to'] ?? null);
            $breaks[$day] = $breakFrom !== null && $breakTo !== null ? $breakFrom . '–' . $breakTo : null;
            $valid = true;
        }
        if (!$valid) {
            return null;
        }

        $allDay = array_filter($pairs, static fn(?array $p): bool => $p !== null && $p[0] <= '00:01' && $p[1] >= '23:59');
        if (count($allDay) === 7) {
            return [['allDay' => true]];
        }

        $groups = [];
        foreach ($pairs as $day => $pair) {
            $text = $pair === null ? null : $pair[0] . '–' . $pair[1];
            $break = $breaks[$day];
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['hours'] === $text && $groups[$last]['break'] === $break) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'hours' => $text, 'break' => $break];
            }
        }

        return $groups;
    }

    private static function dayIndex(mixed $day): ?int
    {
        if (!is_string($day) && !is_int($day)) {
            return null;
        }
        $day = mb_strtolower(trim((string)$day));
        if (preg_match('/^[1-7]$/', $day) === 1) {
            return (int)$day - 1;
        }

        $day = str_replace(['’', 'ʼ', '`'], "'", $day);

        return self::DAYS[mb_substr($day, 0, 3)] ?? self::DAYS[mb_substr($day, 0, 2)] ?? null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]?\d|2[0-4]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $m) === 1 ? sprintf('%02d:%s', (int)$m[1], $m[2]) : null;
    }
}
