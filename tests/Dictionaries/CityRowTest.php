<?php

namespace Modules\Sviat\Meest\Dictionaries;

require_once __DIR__ . '/../Support/DictionaryFixtures.php';

use Modules\Sviat\Meest\Support\DictionaryFixtures;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\CityRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CityRowTest extends TestCase
{
    public static function prefixes(): array
    {
        return [
            ['Місто', 'м.'],
            ['Селище міського типу', 'смт'],
            ['Село', 'с.'],
            ['Селище', 'с-ще'],
            ['Хутір', 'Хутір'],
        ];
    }

    #[DataProvider('prefixes')]
    public function testViewCityPrefixes(string $view, string $expected): void
    {
        $row = CityRow::fromApi(DictionaryFixtures::city(['viewCity' => $view]), DictionaryFixtures::REGION);

        $this->assertNotNull($row);
        $this->assertSame($expected, $row['type_ua']);
    }

    public function testRowShape(): void
    {
        $row = CityRow::fromApi(DictionaryFixtures::city(), strtoupper(DictionaryFixtures::REGION));

        $this->assertSame(
            ['id', 'region_id', 'district_id', 'name_ua', 'type_ua', 'district_ua', 'region_ua', 'search_text', 'row_hash'],
            array_keys($row),
        );
        $this->assertSame('bbbbbbbb-0000-4000-8000-000000000001', $row['id']);
        $this->assertSame(DictionaryFixtures::REGION, $row['region_id']);
        $this->assertSame('cccccccc-0000-4000-8000-000000000001', $row['district_id']);
        $this->assertSame('Лісове', $row['name_ua']);
        $this->assertSame('лісове', $row['search_text']);
        $this->assertArrayNotHasKey('points', $row);
    }

    public function testNamesakesKeepDistrictAndRegion(): void
    {
        $north = CityRow::fromApi(DictionaryFixtures::city(), DictionaryFixtures::REGION);
        $south = CityRow::fromApi(DictionaryFixtures::city([
            'cityID' => 'BBBBBBBB-0000-4000-8000-000000000002',
            'districtDescr' => ['descrUA' => 'Південний'],
            'regionDescr' => ['descrUA' => 'Інша'],
        ]), DictionaryFixtures::REGION);

        $this->assertSame($north['name_ua'], $south['name_ua']);
        $this->assertSame('Північний', $north['district_ua']);
        $this->assertSame('Тестова', $north['region_ua']);
        $this->assertSame('Південний', $south['district_ua']);
        $this->assertSame('Інша', $south['region_ua']);
        $this->assertNotSame($north['row_hash'], $south['row_hash']);
    }

    public function testMissingDistrictIsNull(): void
    {
        $row = CityRow::fromApi(DictionaryFixtures::city(['districtID' => '', 'districtDescr' => []]), DictionaryFixtures::REGION);

        $this->assertNull($row['district_id']);
        $this->assertNull($row['district_ua']);
    }

    public function testDroppedWithoutCityId(): void
    {
        $this->assertNull(CityRow::fromApi(DictionaryFixtures::city(['cityID' => '']), DictionaryFixtures::REGION));
        $this->assertNull(CityRow::fromApi(array_diff_key(DictionaryFixtures::city(), ['cityID' => 1]), DictionaryFixtures::REGION));
    }
}
