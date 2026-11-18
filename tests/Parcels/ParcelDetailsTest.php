<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\ParcelDetails;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use PHPUnit\Framework\TestCase;

/**
 * `estimatedDeliveryDate` у відповіді `POST /parcel` за схемою має вигляд `29.03.2019`. Елемент `parcelsList` за схемою
 * несе лише parcelID, parcelNumber, `вarCode`, registerID, registerType; `fromApi` читає ще поля відповіді
 * `POST /parcel` (receiverName, receiverPhone, notation, estimatedDeliveryDate), якщо Meest їх поверне.
 */
class ParcelDetailsTest extends TestCase
{
    private static function draft(string $name = 'Тестенко  Тест', string $description = 'Одяг'): ParcelDraft
    {
        return new ParcelDraft(
            $name, '+380671234567', (object)['id' => 'AAAAAAAA-0000-4000-8000-000000000001'],
            2.5, 30, 20, 15, 1000.0, true, 'noncash', $description, 'Замовлення 1042',
        );
    }

    public function testDraftPlusEstimatedDate(): void
    {
        $details = ParcelDetails::fromDraft(self::draft(), ['estimatedDeliveryDate' => '29.11.2026', 'receiverName' => 'Інше']);

        self::assertSame([
            'receiver_name' => 'Тестенко Тест',
            'receiver_phone' => '+380671234567',
            'branch_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'description' => 'Одяг',
            'notation' => 'Замовлення 1042',
            'delivery_date' => '2026-11-29',
            'weight_kg' => 2.5,
            'length' => 30,
            'width' => 20,
            'height' => 15,
            'volume' => 0.009,
            'declared' => 1000.0,
        ], $details);
        self::assertSame($details, ParcelDetails::decode(ParcelDetails::encode($details)));
    }

    public function testIsoAndInvalidDates(): void
    {
        self::assertSame('2026-11-29', ParcelDetails::fromDraft(self::draft(), ['estimatedDeliveryDate' => '2026-11-29T00:00:00'])['delivery_date']);
        self::assertArrayNotHasKey('delivery_date', ParcelDetails::fromDraft(self::draft(), ['estimatedDeliveryDate' => '31.02.2026']));
        self::assertArrayNotHasKey('delivery_date', ParcelDetails::fromDraft(self::draft(), []));
    }

    public function testOnlyWhitelist(): void
    {
        $details = ParcelDetails::decode(json_encode([
            'receiver_name' => 'Тестенко Тест',
            'token' => 'secret',
            'html' => '<script>alert(1)</script>',
            'weight_kg' => '2.5',
            'length' => 'abc',
            'width' => -3,
            'declared' => ['nested'],
        ]));

        self::assertSame(['receiver_name' => 'Тестенко Тест', 'weight_kg' => 2.5], $details);
    }

    public function testTextCut(): void
    {
        $details = ParcelDetails::fromDraft(self::draft(str_repeat('Я', 300), str_repeat('о', 400)), []);

        self::assertSame(250, mb_strlen($details['receiver_name']));
        self::assertSame(250, mb_strlen($details['description']));
    }

    public function testCorruptJsonIsEmpty(): void
    {
        self::assertSame([], ParcelDetails::decode('{"receiver_name": "Тест'));
        self::assertSame([], ParcelDetails::decode('["receiver_name"]'));
        self::assertSame([], ParcelDetails::decode(null));
        self::assertSame([], ParcelDetails::decode(''));
        self::assertNull(ParcelDetails::encode([]));
    }

    public function testFromApiReadsOnlyKnownFields(): void
    {
        $details = ParcelDetails::fromApi([
            'parcelID' => 'ffffffff-0000-4000-8000-000000000001',
            'parcelNumber' => '1042-7',
            'вarCode' => '711-2550636',
            'receiverName' => 'Тестенко Тест',
            'receiverPhone' => '+380671234567',
            'notation' => 'Замовлення 1042',
            'estimatedDeliveryDate' => '29.11.2026',
            'registerID' => '0750fc9e-7eac-11e9-80e0-1c98ec135261',
        ]);

        self::assertSame([
            'receiver_name' => 'Тестенко Тест',
            'receiver_phone' => '+380671234567',
            'notation' => 'Замовлення 1042',
            'delivery_date' => '2026-11-29',
        ], $details);
    }
}
