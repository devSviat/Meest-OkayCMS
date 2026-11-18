<?php

namespace Modules\Sviat\Meest\Cart;

use Okay\Modules\Sviat\Meest\Cart\BranchFit;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Cart\FitProblem;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class BranchFitTest extends TestCase
{
    private const string TODAY = '2026-11-17';

    private static function box(int $l = 30, int $w = 20, int $h = 15): ShippingConfig
    {
        return new ShippingConfig('', '', '', '', 1.0, $l, $w, $h, true, 'cash', true, 0.0, '', '{order}');
    }

    private static function branch(array $override = []): object
    {
        return (object)($override + [
            'weight_place_max' => null, 'weight_total_max' => null, 'insurance_max' => null,
            'length_max' => null, 'width_max' => null, 'height_max' => null,
            'inbound_from' => null, 'inbound_to' => null, 'overloaded' => 0,
        ]);
    }

    private static function cart(float $weight = 1.0): CartMetrics
    {
        return new CartMetrics($weight, false, 100.0, 100.0);
    }

    public function testRotatedBoxFits(): void
    {
        $branch = self::branch(['length_max' => 10, 'width_max' => 20, 'height_max' => 60]);

        self::assertSame([], BranchFit::problems(self::cart(), self::box(60, 20, 10), 100.0, $branch, self::TODAY));
    }

    public function testBoxTooBigEvenRotated(): void
    {
        $branch = self::branch(['length_max' => 10, 'width_max' => 20, 'height_max' => 60]);

        self::assertSame([FitProblem::TooBig], BranchFit::problems(self::cart(), self::box(60, 21, 10), 100.0, $branch, self::TODAY));
    }

    public function testNullLimitMeansNoLimit(): void
    {
        self::assertSame([], BranchFit::problems(self::cart(500.0), self::box(300, 300, 300), 1_000_000.0, self::branch(), self::TODAY));
        $oneSide = self::branch(['length_max' => 40, 'width_max' => null, 'height_max' => null]);
        self::assertSame([], BranchFit::problems(self::cart(), self::box(200, 100, 30), 100.0, $oneSide, self::TODAY), 'an unlimited side takes the longest edge');
        self::assertSame([FitProblem::TooBig], BranchFit::problems(self::cart(), self::box(200, 100, 50), 100.0, $oneSide, self::TODAY));
    }

    public function testTooHeavyPlaceAndTotal(): void
    {
        self::assertSame([FitProblem::TooHeavy], BranchFit::problems(self::cart(31.0), self::box(), 1.0, self::branch(['weight_place_max' => '30.00']), self::TODAY));
        self::assertSame([FitProblem::TooHeavy], BranchFit::problems(self::cart(16.0), self::box(), 1.0, self::branch(['weight_total_max' => '15.00']), self::TODAY));
        self::assertSame([], BranchFit::problems(self::cart(30.0), self::box(), 1.0, self::branch(['weight_place_max' => '30.00']), self::TODAY), 'exactly the limit fits');
    }

    public function testOverInsurance(): void
    {
        self::assertSame([FitProblem::OverInsurance], BranchFit::problems(self::cart(), self::box(), 15000.01, self::branch(['insurance_max' => '15000.00']), self::TODAY));
        self::assertSame([], BranchFit::problems(self::cart(), self::box(), 15000.0, self::branch(['insurance_max' => '15000.00']), self::TODAY));
    }

    public function testInboundRestrictionByDate(): void
    {
        $ended = self::branch(['inbound_from' => '2026-11-01 00:00:00', 'inbound_to' => '2026-11-16 00:00:00']);
        $future = self::branch(['inbound_from' => '2026-11-18 00:00:00', 'inbound_to' => '2026-11-30 00:00:00']);
        $now = self::branch(['inbound_from' => '2026-11-10 00:00:00', 'inbound_to' => '2026-11-20 00:00:00']);
        $lastDay = self::branch(['inbound_from' => '2026-11-10 00:00:00', 'inbound_to' => '2026-11-17 00:00:00']);
        $openEnd = self::branch(['inbound_from' => '2026-11-10 00:00:00', 'inbound_to' => null]);
        $openStart = self::branch(['inbound_from' => null, 'inbound_to' => '2026-11-17']);

        self::assertSame([], BranchFit::problems(self::cart(), self::box(), 1.0, $ended, self::TODAY), 'ended yesterday');
        self::assertSame([], BranchFit::problems(self::cart(), self::box(), 1.0, $future, self::TODAY), 'starts tomorrow');
        self::assertSame([FitProblem::Inbound], BranchFit::problems(self::cart(), self::box(), 1.0, $now, self::TODAY));
        self::assertSame([FitProblem::Inbound], BranchFit::problems(self::cart(), self::box(), 1.0, $lastDay, self::TODAY), 'the end date is still restricted');
        self::assertSame([FitProblem::Inbound], BranchFit::problems(self::cart(), self::box(), 1.0, $openEnd, self::TODAY));
        self::assertSame([FitProblem::Inbound], BranchFit::problems(self::cart(), self::box(), 1.0, $openStart, self::TODAY));
        self::assertSame('2026-11-20', BranchFit::inboundUntil($now));
        self::assertNull(BranchFit::inboundUntil($openEnd));
    }

    public function testOverloadedIsNotAProblem(): void
    {
        self::assertSame([], BranchFit::problems(self::cart(), self::box(), 1.0, self::branch(['overloaded' => 1]), self::TODAY));
    }
}
