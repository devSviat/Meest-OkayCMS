<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Повна відповідь `GET tracking/{barCode}` для прив'язки й «Оновити статус»: замінює історію посилки
 * (≤ ParcelHistory::MAX_EVENTS найновіших) і ставить останню подію поточною.
 */
final class TrackingAnswer
{
    /**
     * Читабельні події саме цієї посилки, хронологічно, без повторів. Відповідь уже відібрана за штрихкодом,
     * тож подія без читабельного поля штрихкоду належить запитаній посилці.
     *
     * @param list<array<mixed>> $items
     * @return list<TrackingEvent>
     */
    public static function events(array $items, string $barcode): array
    {
        $key = Barcode::key($barcode);
        $events = [];
        foreach ($items as $item) {
            $raw = $item['barCode'] ?? $item['вarCode'] ?? null;
            if (!is_string($raw) || Barcode::parse($raw) === null) {
                $item['barCode'] = $barcode;
            }
            $event = TrackingEvent::fromApi($item);
            if ($event !== null && Barcode::key($event->barcode) === $key) {
                $events[$event->code . '|' . $event->at] = $event;
            }
        }
        $events = array_values($events);
        usort($events, static fn(TrackingEvent $a, TrackingEvent $b): int => $a->at <=> $b->at);

        return $events;
    }

    /**
     * Колонки ParcelEntity за повною історією. Група — останній відомий код; невідомі коди її не змінюють.
     *
     * @param non-empty-list<TrackingEvent> $events хронологічно, як з events()
     * @return array{status_code: int, event_name: string, event_at: string, status_group: ?string, arrived_at: ?string, history: ?string}
     */
    public static function values(array $events, ?string $group, ?string $arrivedAt): array
    {
        $firstArrival = null;
        foreach ($events as $event) {
            $group = ParcelStatus::next($group, $event->code);
            if ($firstArrival === null && ParcelStatus::group($event->code) === 'arrived') {
                $firstArrival = $event->at;
            }
        }
        $latest = $events[array_key_last($events)];

        return [
            'status_code' => $latest->code,
            'event_name' => mb_substr($latest->name, 0, ParcelHistory::TEXT_LENGTH),
            'event_at' => $latest->at,
            'status_group' => $group,
            'arrived_at' => $firstArrival ?? $arrivedAt,
            'history' => ParcelHistory::encode(ParcelHistory::fromEvents($events)),
        ];
    }
}
