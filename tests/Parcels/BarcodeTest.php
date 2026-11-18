<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\Barcode;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase
{
    public function testSpacesRemovedAndUppercased(): void
    {
        self::assertSame('7112550636', Barcode::parse(' 711 2550636 '));
        self::assertSame('711-2550636', Barcode::parse('711-2550636'));
        self::assertSame('ABC12345', Barcode::parse(' abc 12345'));
    }

    public function testRejectsPathCharacters(): void
    {
        self::assertNull(Barcode::parse('711/../x'));
        self::assertNull(Barcode::parse('a?b'));
        self::assertNull(Barcode::parse('711%2F2550636'));
    }

    public function testRejectsWrongShape(): void
    {
        self::assertNull(Barcode::parse(''));
        self::assertNull(Barcode::parse('1234'));
        self::assertNull(Barcode::parse('-1234567'));
        self::assertNull(Barcode::parse('1234567-'));
        self::assertNull(Barcode::parse(str_repeat('1', 21)));
        self::assertSame(str_repeat('1', 20), Barcode::parse(str_repeat('1', 20)));
    }
}
