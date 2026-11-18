<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\MeestPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeestPhoneTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function inputs(): iterable
    {
        yield 'spaces, brackets, dashes' => ['+38 (067) 123-45-67', '+380671234567'];
        yield 'national' => ['0671234567', '+380671234567'];
        yield 'without plus' => ['380671234567', '+380671234567'];
        yield 'with plus' => ['+380671234567', '+380671234567'];
        yield 'too short' => ['12345', null];
        yield 'empty' => ['', null];
        yield 'letters' => ['067abc4567', null];
        yield 'plus inside' => ['067+1234567', null];
        yield 'foreign' => ['+48501234567', null];
        yield 'extra digit' => ['+3806712345678', null];
    }

    #[DataProvider('inputs')]
    public function testNormalize(string $raw, ?string $expected): void
    {
        self::assertSame($expected, MeestPhone::normalize($raw));
    }

    public function testLandlineIsNotMobile(): void
    {
        self::assertFalse(MeestPhone::isMobile('+380441234567'));
        self::assertTrue(MeestPhone::isMobile('+380671234567'));
        self::assertFalse(MeestPhone::isMobile('0671234567'));
    }
}
