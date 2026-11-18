<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\TrackingEvent;
use PHPUnit\Framework\TestCase;

class TrackingEventTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /** Форма елемента з openAPI_ua.json (`/tracking/{trackNumber}`, `/trackingByPeriod`). */
    private static function item(array $override = []): array
    {
        return $override + [
            'parcelNumber' => 'TAC-2550636',
            'barCode' => '711-2550636',
            'eventDateTime' => '15.07.2026 12:00:00',
            'eventCode' => 606,
            'eventCodeUPU' => 'EMI',
            'eventPlaces' => '1/1',
            'eventCityDescr' => ['descrUA' => 'Львів', 'descrRU' => 'Львов', 'descrEN' => 'Lviv'],
            'eventDescr' => ['descrUA' => 'Надходження до підрозділу відправника'],
        ];
    }

    public function testKyivTimeToAppZone(): void
    {
        $event = TrackingEvent::fromApi(self::item());

        self::assertSame('2026-07-15 09:00:00', $event?->at);
        self::assertSame('2026-01-15 10:00:00', TrackingEvent::fromApi(self::item(['eventDateTime' => '2026-01-15 12:00:00']))?->at);
        self::assertSame('2026-07-15 09:00:00', TrackingEvent::fromApi(self::item(['eventDateTime' => '2026-07-15T12:00:00']))?->at);
    }

    public function testExplicitOffsetIsKept(): void
    {
        self::assertSame('2026-07-15 12:00:00', TrackingEvent::fromApi(self::item(['eventDateTime' => '2026-07-15T12:00:00+00:00']))?->at);
    }

    public function testTypoBarcodeAndStringCode(): void
    {
        $item = self::item(['eventCode' => '1622']);
        unset($item['barCode']);
        $item['вarCode'] = '711-2550636';

        $event = TrackingEvent::fromApi($item);

        self::assertSame('711-2550636', $event?->barcode);
        self::assertSame(1622, $event?->code);
    }

    public function testPlaceIsEventCityNotPlacesCount(): void
    {
        $event = TrackingEvent::fromApi(self::item());

        self::assertSame('Львів', $event?->place);
        self::assertSame('Надходження до підрозділу відправника', $event?->name);
        self::assertSame('711-2550636|606|2026-07-15 09:00:00', $event?->key());
    }

    public function testMissingCityGivesEmptyPlace(): void
    {
        self::assertSame('', TrackingEvent::fromApi(self::item(['eventCityDescr' => null]))?->place);
    }

    public function testUnreadableEventIsNull(): void
    {
        self::assertNull(TrackingEvent::fromApi(self::item(['eventDateTime' => 'вчора'])));
        self::assertNull(TrackingEvent::fromApi(self::item(['eventCode' => 'x'])));
        self::assertNull(TrackingEvent::fromApi(self::item(['barCode' => ''])));
        self::assertNull(TrackingEvent::fromApi([]));
    }
}
