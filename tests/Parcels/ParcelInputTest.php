<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\ParcelInput;
use PHPUnit\Framework\TestCase;

class ParcelInputTest extends TestCase
{
    public function testKnownFieldsOnlyAndNumbersParsed(): void
    {
        $input = ParcelInput::fromPost([
            'receiver_name' => '  Тест Тестенко ',
            'receiver_phone' => '0671234567',
            'weight' => '2,5',
            'length' => '30',
            'width' => 'abc',
            'declared_value' => '1000.50',
            'payer' => 'sender',
            'pay_type' => 'noncash',
            'description' => 'Одяг',
            'notation' => 'Замовлення 1',
            'order_id' => '9',
            'branch_id' => 'x',
            'receiver_name_extra' => ['a'],
        ]);

        self::assertSame('Тест Тестенко', $input['receiver_name']);
        self::assertSame(2.5, $input['weight']);
        self::assertSame(30.0, $input['length']);
        self::assertSame('abc', $input['width']);
        self::assertSame(1000.5, $input['declared_value']);
        self::assertSame('sender', $input['payer']);
        self::assertArrayNotHasKey('order_id', $input);
        self::assertArrayNotHasKey('branch_id', $input);
        self::assertArrayNotHasKey('height', $input);
    }

    public function testTextIsTruncated(): void
    {
        $input = ParcelInput::fromPost(['description' => str_repeat('а', 400), 'receiver_name' => str_repeat('б', 400)]);

        self::assertSame(250, mb_strlen($input['description']));
        self::assertSame(250, mb_strlen($input['receiver_name']));
    }

    public function testNonStringIgnored(): void
    {
        self::assertSame([], ParcelInput::fromPost(['weight' => ['1'], 'description' => ['x']]));
    }
}
