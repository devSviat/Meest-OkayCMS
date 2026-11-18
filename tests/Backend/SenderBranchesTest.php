<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/FakeDictionaryEntities.php';

use Modules\Sviat\Meest\Support\FakeDictionaryEntities;
use Okay\Modules\Sviat\Meest\Backend\SenderBranches;
use PHPUnit\Framework\TestCase;

class SenderBranchesTest extends TestCase
{
    use FakeDictionaryEntities;

    public const string CITY = '0a1b2c3d-0000-4000-8000-0000000000c1';
    public const string OTHER_CITY = '0a1b2c3d-0000-4000-8000-0000000000c2';

    /** @return list<array<string, mixed>> */
    public static function branches(): array
    {
        $row = static fn(string $id, string $city, string $number, int $receivingOnly, int $sendingOnly = 0): array => [
            'id' => $id, 'city_id' => $city, 'number' => $number, 'name_ua' => 'Відділення ' . $number,
            'address_ua' => 'вул. Вигадана, ' . $number, 'receiving_only' => $receivingOnly, 'sending_only' => $sendingOnly,
        ];

        return [
            $row('0a1b2c3d-0000-4000-8000-0000000000b1', self::CITY, '1', 0),
            $row('0a1b2c3d-0000-4000-8000-0000000000b2', self::CITY, '2', 1),
            $row('0a1b2c3d-0000-4000-8000-0000000000b3', self::CITY, '3', 0, 1),
            $row('0a1b2c3d-0000-4000-8000-0000000000b4', self::OTHER_CITY, '4', 0),
        ];
    }

    private function branches_(): SenderBranches
    {
        return new SenderBranches($this->dictionaryFactory(
            [['id' => self::CITY, 'name_ua' => 'Вигадане', 'search_text' => 'вигадане']],
            self::branches(),
        ));
    }

    public function testReceivingOnlyNotOffered(): void
    {
        $ids = array_column($this->branches_()->forCity(self::CITY), 'id');

        self::assertSame(['0a1b2c3d-0000-4000-8000-0000000000b1', '0a1b2c3d-0000-4000-8000-0000000000b3'], $ids);
    }

    public function testOptionsCarryNumberTitleAndAddress(): void
    {
        $option = $this->branches_()->forCity(self::CITY)[0];

        self::assertSame(['id', 'number', 'title', 'address'], array_keys($option));
        self::assertSame('1', $option['number']);
        self::assertStringContainsString('вул. Вигадана, 1', $option['title']);
    }

    public function testBlankCityOffersNothing(): void
    {
        self::assertSame([], $this->branches_()->forCity(''));
    }

    public function testSendableChecksCityAndDirection(): void
    {
        $branches = $this->branches_();

        self::assertTrue($branches->isSendable(self::CITY, '0a1b2c3d-0000-4000-8000-0000000000b1'));
        self::assertFalse($branches->isSendable(self::CITY, '0a1b2c3d-0000-4000-8000-0000000000b2'), 'receiving only');
        self::assertFalse($branches->isSendable(self::CITY, '0a1b2c3d-0000-4000-8000-0000000000b4'), 'other city');
        self::assertFalse($branches->isSendable(self::CITY, ''));
    }

    public function testBranchesSortedByNumberNotAsText(): void
    {
        $row = static fn(string $id, string $number): array => [
            'id' => $id, 'city_id' => self::CITY, 'number' => $number, 'name_ua' => 'Відділення ' . $number,
            'address_ua' => '', 'receiving_only' => 0, 'sending_only' => 0,
        ];
        $branches = new SenderBranches($this->dictionaryFactory([], [
            $row('0a1b2c3d-0000-4000-8000-0000000000e1', '100'),
            $row('0a1b2c3d-0000-4000-8000-0000000000e2', '10'),
            $row('0a1b2c3d-0000-4000-8000-0000000000e3', '2'),
            $row('0a1b2c3d-0000-4000-8000-0000000000e4', '1'),
        ]));

        self::assertSame(['1', '2', '10', '100'], array_column($branches->forCity(self::CITY), 'number'));
    }


}
