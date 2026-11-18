<?php

namespace Okay\Modules\Sviat\Meest\Cart;

use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/**
 * Чи прийме пункт посилку за його лімітами з довідника. NULL-ліміт — без обмеження.
 * Перевантаженість пункту (`overloaded`) — лише примітка: Meest посилку прийме.
 */
final class BranchFit
{
    /**
     * @param float $declared оголошена цінність у гривнях
     * @param string $today Y-m-d за Києвом
     * @return list<FitProblem>
     */
    public static function problems(CartMetrics $cart, ShippingConfig $box, float $declared, object $branch, string $today): array
    {
        $problems = [];
        foreach (['weight_place_max', 'weight_total_max'] as $field) {
            $max = self::limit($branch->$field ?? null);
            if ($max !== null && $cart->knownWeight > $max + 1e-9) {
                $problems[] = FitProblem::TooHeavy;
                break;
            }
        }
        if (!self::boxFits($box, $branch)) {
            $problems[] = FitProblem::TooBig;
        }
        $insurance = self::limit($branch->insurance_max ?? null);
        if ($insurance !== null && $declared > $insurance + 1e-9) {
            $problems[] = FitProblem::OverInsurance;
        }
        if (self::restrictedOn($branch, $today)) {
            $problems[] = FitProblem::Inbound;
        }

        return $problems;
    }

    /** До якого дня діє обмеження приймання; null — дати кінця немає. */
    public static function inboundUntil(object $branch): ?string
    {
        return self::day($branch->inbound_to ?? null);
    }

    /** Коробку можна повернути: найдовша сторона йде проти найдовшого ліміту; сторона без ліміту бере будь-яку. */
    private static function boxFits(ShippingConfig $box, object $branch): bool
    {
        $sides = [$box->lengthCm, $box->widthCm, $box->heightCm];
        $limits = array_map(
            static fn(string $field): float => self::limit($branch->$field ?? null) ?? INF,
            ['length_max', 'width_max', 'height_max'],
        );
        sort($sides);
        sort($limits);
        foreach ($sides as $i => $side) {
            if ($side > $limits[$i] + 1e-9) {
                return false;
            }
        }

        return true;
    }

    /** Колонки — datetime з нульовим часом, тож порівнюються лише дати; відсутня межа відкрита. */
    private static function restrictedOn(object $branch, string $today): bool
    {
        $from = self::day($branch->inbound_from ?? null);
        $to = self::day($branch->inbound_to ?? null);
        if ($from === null && $to === null) {
            return false;
        }

        return ($from === null || $from <= $today) && ($to === null || $today <= $to);
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }

    private static function limit(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }
}
