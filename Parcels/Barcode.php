<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/** Штрихкод Meest, як його вводять люди: пробіли прибираються, літери стають великими. */
final class Barcode
{
    public static function parse(string $raw): ?string
    {
        $barcode = strtoupper((string)preg_replace('/\s+/u', '', $raw));

        // Штрихкод потрапляє в шлях `tracking/{barCode}`, тож крім літер, цифр і дефіса нічого не пропускається.
        return preg_match('/^[A-Z0-9][A-Z0-9-]{4,18}[A-Z0-9]$/', $barcode) === 1 ? $barcode : null;
    }

    /** Те саме відправлення Meest пише то з дефісом (`711-2550636`), то без: порівнюються штрихкоди без дефісів. */
    public static function key(string $barcode): string
    {
        return str_replace('-', '', $barcode);
    }
}
