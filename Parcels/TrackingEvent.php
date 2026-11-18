<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Подія трекінгу Meest (`tracking`, `trackingByPeriod`). Нечитабельна подія — null, до історії вона не потрапляє.
 * Поле міста події — `eventCityDescr.descrUA`; `eventPlaces` — кількість місць («1/1»), а не місто.
 */
final readonly class TrackingEvent
{
    private const string MEEST_ZONE = 'Europe/Kyiv';
    private const array LOCAL_FORMATS = ['!d.m.Y H:i:s', '!Y-m-d H:i:s'];

    /** @param string $at `Y-m-d H:i:s` у поясі застосунку */
    public function __construct(
        public string $barcode,
        public int $code,
        public string $at,
        public string $name,
        public string $place = '',
    ) {
    }

    /** @param array<string, mixed> $e */
    public static function fromApi(array $e): ?self
    {
        // `вarCode` з кириличною «в» — так поле названо в частині відповідей схеми.
        $raw = $e['barCode'] ?? $e['вarCode'] ?? null;
        $barcode = is_string($raw) ? Barcode::parse($raw) : null;
        $code = $e['eventCode'] ?? null;
        $code = is_int($code) || (is_string($code) && ctype_digit($code)) ? (int)$code : null;
        $at = self::appTime($e['eventDateTime'] ?? null);
        if ($barcode === null || $code === null || $at === null) {
            return null;
        }

        return new self(
            $barcode,
            $code,
            $at,
            self::text($e['eventDescr'] ?? null),
            self::text($e['eventCityDescr'] ?? null),
        );
    }

    public function key(): string
    {
        return $this->barcode . '|' . $this->code . '|' . $this->at;
    }

    private static function text(mixed $descr): string
    {
        $value = is_array($descr) ? ($descr['descrUA'] ?? null) : null;

        return is_string($value) ? trim($value) : '';
    }

    /** Час без пояса — київський: так його пише Meest. */
    private static function appTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $kyiv = new \DateTimeZone(self::MEEST_ZONE);
        $moment = false;
        foreach (self::LOCAL_FORMATS as $format) {
            $moment = \DateTimeImmutable::createFromFormat($format, $value, $kyiv);
            if ($moment !== false && \DateTimeImmutable::getLastErrors() === false) {
                break;
            }
            $moment = false;
        }
        if ($moment === false) {
            try {
                $moment = new \DateTimeImmutable($value, $kyiv);
            } catch (\Exception) {
                return null;
            }
        }

        return $moment->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
