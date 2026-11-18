<?php

namespace Modules\Sviat\Meest\Dictionaries;

require_once __DIR__ . '/../Support/DictionaryFixtures.php';

use Modules\Sviat\Meest\Support\DictionaryFixtures;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchRow;
use PHPUnit\Framework\TestCase;

class BranchRowTest extends TestCase
{
    private const array COLUMNS = [
        'id', 'region_id', 'city_id', 'number', 'kind', 'type_code', 'name_ua', 'address_ua', 'zip', 'lat', 'lng',
        'schedule_json', 'weight_place_max', 'weight_total_max', 'volume_place_max', 'insurance_max',
        'length_max', 'width_max', 'height_max', 'phone_required', 'sending_only',
        'receiving_only', 'inbound_from', 'inbound_to', 'overloaded', 'row_hash',
    ];

    private function row(array $override = []): array
    {
        $row = BranchRow::fromApi(DictionaryFixtures::branch($override), DictionaryFixtures::REGION);
        $this->assertNotNull($row);

        return $row;
    }

    public function testClosedExcluded(): void
    {
        $this->assertNull(BranchRow::fromApi(DictionaryFixtures::branch(['isBranchClosed' => true]), DictionaryFixtures::REGION));
    }

    public function testPointExcluded(): void
    {
        $b = DictionaryFixtures::branch(['branchType' => 'ТОЧКА', 'branchTypeAPP' => '']);

        $this->assertNull(BranchRow::fromApi($b, DictionaryFixtures::REGION));
        $this->assertNull(BranchRow::unknownType($b));
    }

    public function testUnknownTypeReported(): void
    {
        $b = DictionaryFixtures::branch(['branchType' => 'XYZ', 'branchTypeAPP' => '7']);

        $this->assertNull(BranchRow::fromApi($b, DictionaryFixtures::REGION));
        $this->assertSame('XYZ', BranchRow::unknownType($b));
        $this->assertNull(BranchRow::unknownType(DictionaryFixtures::branch()));
    }

    public function testZeroLimitIsNull(): void
    {
        $zero = $this->row(['branchLimits' => ['weightPlaceMax' => '0', 'weightTotalMax' => 0, 'volumePlaceMax' => '0', 'insuranceTotalMax' => 0.0]]);
        $this->assertNull($zero['weight_place_max']);
        $this->assertNull($zero['weight_total_max']);
        $this->assertNull($zero['volume_place_max']);
        $this->assertNull($zero['insurance_max']);

        $this->assertSame(30.0, $this->row(['branchLimits' => ['weightPlaceMax' => '30']])['weight_place_max']);
    }

    public function testMissingLimitsAreNull(): void
    {
        $b = DictionaryFixtures::branch();
        unset($b['branchLimits']);
        $row = BranchRow::fromApi($b, DictionaryFixtures::REGION);

        $this->assertNotNull($row);
        $this->assertNull($row['weight_total_max']);
        $this->assertNull($row['length_max']);
        $this->assertSame(0, $row['sending_only']);
    }

    public function testDimensionsAreIntegers(): void
    {
        $row = $this->row();

        $this->assertSame([61, 37, 35], [$row['length_max'], $row['width_max'], $row['height_max']]);
        $this->assertSame(0.08, $row['volume_place_max']);
    }

    public function testPhoneRequiredAndFlags(): void
    {
        $row = $this->row(['branchLimits' => ['receiverCellPhoneRequired' => true], 'overloaded' => true]);

        $this->assertSame(1, $row['phone_required']);
        $this->assertSame(1, $row['overloaded']);
    }

    public function testSendingOnlyKept(): void
    {
        $row = $this->row(['branchLimits' => ['sendingOnly' => true, 'receivingOnly' => false]]);

        $this->assertSame(1, $row['sending_only']);
        $this->assertSame(0, $row['receiving_only']);
        $this->assertSame(1, $this->row(['branchLimits' => ['receivingOnly' => true]])['receiving_only']);
    }

    public function testUuidLowercased(): void
    {
        $row = $this->row();

        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $row['id']);
        $this->assertSame('bbbbbbbb-0000-4000-8000-000000000001', $row['city_id']);
        $this->assertSame(DictionaryFixtures::REGION, $row['region_id']);
    }

    public function testInboundDates(): void
    {
        $row = $this->row([
            'InboundRestriction' => true,
            'InboundRestrictionStartDate' => '2025-11-19T00:00:00',
            'InboundRestrictionEndDate' => '2025-11-29T00:00:00',
        ]);

        $this->assertSame('2025-11-19', $row['inbound_from']);
        $this->assertSame('2025-11-29', $row['inbound_to']);

        $plain = $this->row();
        $this->assertNull($plain['inbound_from']);
        $this->assertNull($plain['inbound_to']);
    }

    public function testInboundDatesIgnoredWithoutFlag(): void
    {
        $row = $this->row(['InboundRestriction' => false, 'InboundRestrictionStartDate' => '2025-11-19T00:00:00', 'InboundRestrictionEndDate' => '2025-11-29T00:00:00']);

        $this->assertNull($row['inbound_from']);
    }

    public function testBadInboundDateIsNull(): void
    {
        $row = $this->row(['InboundRestriction' => true, 'InboundRestrictionStartDate' => 'не дата', 'InboundRestrictionEndDate' => '2025-11-29T00:00:00']);

        $this->assertNull($row['inbound_from']);
        $this->assertSame('2025-11-29', $row['inbound_to']);
    }

    public function testAddressAndScheduleAndKind(): void
    {
        $row = $this->row();

        $this->assertSame('вул. Лісова 1', $row['address_ua']);
        $this->assertSame('branch', $row['kind']);
        $this->assertSame('ППВ', $row['type_code']);
        $this->assertSame('01', $row['number']);
        $this->assertSame('00001', $row['zip']);
        $this->assertSame(50.1234567, $row['lat']);
        $this->assertSame(
            [
                ['day' => 'Пн', 'from' => '09:00', 'to' => '18:00', 'break_from' => '13:00', 'break_to' => '14:00'],
                ['day' => 'Сб', 'from' => '10:00', 'to' => '15:00', 'break_from' => null, 'break_to' => null],
            ],
            json_decode($row['schedule_json'], true),
        );
    }

    public function testPostomatKind(): void
    {
        $row = $this->row(['branchType' => 'АПТ', 'branchTypeAPP' => '3']);

        $this->assertSame('postomat', $row['kind']);
    }

    public function testManagerPersonalDataNotStored(): void
    {
        $row = $this->row();

        $this->assertSame(self::COLUMNS, array_keys($row));
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
        foreach (['Тестенко', '380000000001', 'example.invalid', 'Карткою', 'генератор'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    public function testRowHashChangesWithLimits(): void
    {
        $this->assertNotSame($this->row()['row_hash'], $this->row(['branchLimits' => ['weightPlaceMax' => 10]])['row_hash']);
    }

    public function testEmptyAddressIsEmptyString(): void
    {
        $row = $this->row(['addressDescr' => [], 'building' => '']);

        $this->assertSame('', $row['address_ua']);
    }

    public function testDroppedWithoutBranchId(): void
    {
        $this->assertNull(BranchRow::fromApi(DictionaryFixtures::branch(['branchID' => '']), DictionaryFixtures::REGION));
    }

    public function testDroppedWithoutCityId(): void
    {
        $this->assertNull(BranchRow::fromApi(DictionaryFixtures::branch(['cityID' => null]), DictionaryFixtures::REGION));
    }

    public function testNameFallsBackToFullName(): void
    {
        $row = $this->row(['ShortName' => '']);

        $this->assertSame('Відділення №9001 до 30 кг, Тестове, вул. Лісова 1', $row['name_ua']);
    }

    public function testOutOfRangeLimitIsNull(): void
    {
        $row = $this->row(['branchLimits' => ['weightPlaceMax' => 1000000, 'gabaritesMax' => ['length' => 3000000000, 'width' => 37, 'height' => 35]]]);

        $this->assertNull($row['weight_place_max']);
        $this->assertNull($row['length_max']);
        $this->assertSame(37, $row['width_max']);
    }
}
