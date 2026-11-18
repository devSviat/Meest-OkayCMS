<?php

namespace Modules\Sviat\Meest\Extenders;

use Okay\Modules\Sviat\Meest\Extenders\FrontExtender;
use PHPUnit\Framework\TestCase;

/** З чим відкривається кошик: надіслана форма, інакше вибір, розрахований у цій сесії, інакше останнє замовлення. */
class PrefillChoiceTest extends TestCase
{
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string BRANCH = 'aaaaaaaa-0000-4000-8000-000000000001';

    private static function last(): object
    {
        return (object)['city_id' => self::CITY, 'branch_id' => self::BRANCH];
    }

    public function testPostedWins(): void
    {
        $posted = ['city_id' => 'bbbbbbbb-0000-4000-8000-000000000007', 'branch_id' => ''];

        self::assertSame($posted, FrontExtender::prefillChoice($posted, ['city_id' => 'x', 'branch_id' => 'y'], self::last()));
    }

    public function testSessionBeforeLastOrder(): void
    {
        $session = ['city_id' => 'bbbbbbbb-0000-4000-8000-000000000008', 'branch_id' => 'aaaaaaaa-0000-4000-8000-000000000008'];

        self::assertSame($session, FrontExtender::prefillChoice(null, $session, self::last()));
    }

    public function testLastOrderWhenNothingElse(): void
    {
        self::assertSame(['city_id' => self::CITY, 'branch_id' => self::BRANCH], FrontExtender::prefillChoice(null, [], self::last()));
        self::assertSame([], FrontExtender::prefillChoice(null, [], null));
    }
}
