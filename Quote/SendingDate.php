<?php

namespace Okay\Modules\Sviat\Meest\Quote;

/**
 * Дата відправки для `calculate`: без неї Meest рахує від сьогодні, і після останнього за день
 * відправлення орієнтовна дата доставки виходить на день раніше справжньої.
 */
final class SendingDate
{
    /** @param string $cutoff HH:MM за Києвом; посилка, оформлена о цій порі чи пізніше, їде завтра */
    public static function for(int $now, string $cutoff): string
    {
        $at = new \DateTimeImmutable('@' . $now)->setTimezone(new \DateTimeZone('Europe/Kyiv'));
        $today = preg_match('/^(\d{2}):(\d{2})$/', $cutoff, $m) === 1 && $at < $at->setTime((int)$m[1], (int)$m[2]);

        return ($today ? $at : $at->modify('+1 day'))->format('d.m.Y');
    }
}
