<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/** Телефон у вигляді `+380XXXXXXXXX`, який Meest приймає для відправника й одержувача. */
final class MeestPhone
{
    /** Коди операторів мобільного зв'язку України; решта кодів — міські номери. */
    public const array MOBILE_CODES = ['39', '50', '63', '66', '67', '68', '73', '75', '77', '89', '91', '92', '93', '94', '95', '96', '97', '98', '99'];

    /** Приймає `+380…`, `380…` і `0XX…` з пробілами, дужками й дефісами; усе інше, зокрема літери, дає null. */
    public static function normalize(string $raw): ?string
    {
        if (preg_match('/^\+?[\d\s()\-]+$/', trim($raw)) !== 1) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }

        return preg_match('/^380\d{9}$/', $digits) === 1 ? '+' . $digits : null;
    }

    /** @param string $normalized результат normalize() */
    public static function isMobile(string $normalized): bool
    {
        return preg_match('/^\+380(\d{2})\d{7}$/', $normalized, $m) === 1 && in_array($m[1], self::MOBILE_CODES, true);
    }
}
