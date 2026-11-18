<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Storage\Mutex;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;
use Psr\Log\LoggerInterface;

/**
 * Статуси посилок. Вебхуків Meest не має: тік читає вікна `trackingByPeriod` (усі посилки договору) і пише події
 * лише своїх посилок, потім поодинці перевіряє ті, що довго мовчать. Статусів замовлень модуль не змінює.
 */
final class ParcelTracking
{
    /** Посилка, що все ще в русі через два місяці, застрягла; повторні запити лише гальмують чергу. */
    public const int TRACK_FOR_DAYS = 60;
    public const int SAFETY_LIMIT = 30;
    public const int SILENT_AFTER = 21600;

    /** Тайм-аут — це Unreachable, і він зупиняє тік: зависла Meest забирає 30 с, а не весь тік у 600 с. */
    private const int TIMEOUT_SECONDS = 30;
    private const string MUTEX = 'track';
    private const string UNKNOWN_CODES = 'unknown_codes';
    private const int UNKNOWN_CODES_KEPT = 200;

    private readonly MeestClient $client;
    /** @var ?list<int> */
    private ?array $unknownCodes = null;

    /** @param \Closure(): int $now */
    public function __construct(
        MeestClient $client,
        private readonly ParcelRows $rows,
        private readonly TrackingCursor $cursor,
        private readonly Mutex $mutex,
        private readonly AuthFlags $auth,
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        private readonly ParcelIssues $issues,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
    ) {
        $this->client = $client->quick(self::TIMEOUT_SECONDS);
    }

    /**
     * Планувальник. Відмова авторизації зупиняє тік і лишає позначку `tracking`; недоступна Meest чи вичерпаний
     * бюджет теж зупиняють: решта запитів тіку впала б так само.
     */
    public function tick(): void
    {
        if (!$this->mutex->acquire(self::MUTEX)) {
            return;
        }
        try {
            if (!$this->credentials->get()->configured()) {
                return;
            }
            $this->pollWindows();
            $this->pollSafety();
        } catch (MeestApiException $e) {
            // Клієнт уже записав запит і вид помилки в лог.
            $this->auth->record(AuthFlags::TRACKING, $e);
        } finally {
            $this->mutex->release(self::MUTEX);
        }
    }

    /** Кнопка «Оновити статус»: одна посилка зараз, історія замінюється повною відповіддю. */
    public function refresh(int $orderId): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        $barcode = is_string($row->barcode ?? null) ? $row->barcode : '';
        if ($row === null || PendingParcel::is($row) || $barcode === '') {
            return ParcelOutcome::refused('sviat__meest__parcel_err_none');
        }
        try {
            $items = $this->history($barcode);
        } catch (MeestApiException $e) {
            $this->auth->record(AuthFlags::TRACKING, $e);

            return ParcelOutcome::failed($this->issues->explain($e));
        }
        $this->auth->clear(AuthFlags::TRACKING);
        $events = TrackingAnswer::events($items, $barcode);
        if ($events === []) {
            $this->rows->markChecked($orderId, $this->stamp());

            return new ParcelOutcome(true, $barcode, [], ['sviat__meest__track_no_events']);
        }
        $values = TrackingAnswer::values($events, self::text($row->status_group ?? null), self::text($row->arrived_at ?? null));
        $this->rows->saveEvent($orderId, $values + ['checked_at' => $this->stamp()]);

        return new ParcelOutcome(true, $barcode);
    }

    private function pollWindows(): void
    {
        $since = date('Y-m-d H:i:s', ($this->now)() - self::TRACK_FOR_DAYS * 86400);
        $catchingUp = true;
        foreach ($this->cursor->windows($this->rows->oldestUnfinishedCreatedAt($since)) as $window) {
            if ($window['advances'] && !$catchingUp) {
                continue;
            }
            try {
                $items = $this->window($window['from'], $window['to']);
            } catch (MeestApiException $e) {
                if (!self::onlyThisRequest($e)) {
                    throw $e;
                }
                // Курсор лишається перед цим вікном: наступний тік почне з нього, а не перескочить пропущене.
                if ($window['advances']) {
                    $catchingUp = false;
                }
                continue;
            }
            $this->auth->clear(AuthFlags::TRACKING);
            $this->apply($items);
            if ($window['advances']) {
                $this->cursor->advance($window['to']);
            }
        }
    }

    /**
     * @return list<array<mixed>>
     * @throws MeestApiException
     */
    private function window(int $from, int $to): array
    {
        try {
            return MeestClient::rows($this->client->call('GET', TrackingCursor::path($from, $to)));
        } catch (MeestApiException $e) {
            // Як parcelsList на порожній день: інакше курсор застряг би на першій тихій нічній годині.
            if ($e->kind === ApiErrorKind::NotFound) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Події вікна — для всіх посилок договору; пишуться лише ті, чиї штрихкоди є в таблиці.
     *
     * @param list<array<mixed>> $items
     */
    private function apply(array $items): void
    {
        $byBarcode = [];
        foreach ($items as $item) {
            $event = TrackingEvent::fromApi($item);
            if ($event !== null) {
                $byBarcode[Barcode::key($event->barcode)][$event->key()] = $event;
            }
        }
        if ($byBarcode === []) {
            return;
        }
        $barcodes = [];
        foreach ($byBarcode as $events) {
            foreach ($events as $event) {
                $barcodes[$event->barcode] = true;
            }
        }
        foreach ($this->rows->byBarcodes(array_map(strval(...), array_keys($barcodes))) as $row) {
            $events = $byBarcode[Barcode::key((string)$row->barcode)] ?? [];
            if ($events !== []) {
                $this->record($row, array_values($events));
            }
        }
    }

    /**
     * Дописує нові події в історію. Поточною подія стає, лише якщо вона новіша за збережену: перекриті вікна
     * приносять і старіші події, і вони не відкочують статус.
     *
     * @param list<TrackingEvent> $events
     * @return bool чи записано хоч одну нову подію
     */
    private function record(object $row, array $events): bool
    {
        usort($events, static fn(TrackingEvent $a, TrackingEvent $b): int => $a->at <=> $b->at);
        $history = ParcelHistory::decode($row->history ?? null);
        $latestAt = self::text($row->event_at ?? null) ?? '';
        $group = self::text($row->status_group ?? null);
        $arrivedAt = self::text($row->arrived_at ?? null);
        $values = [];
        foreach ($events as $event) {
            $appended = ParcelHistory::append($history, $event);
            if ($appended === $history) {
                continue;
            }
            $history = $appended;
            $this->noteUnknown($event->code);
            if (ParcelStatus::group($event->code) === 'arrived' && ($arrivedAt === null || $event->at < $arrivedAt)) {
                $arrivedAt = $values['arrived_at'] = $event->at;
            }
            if ($event->at > $latestAt) {
                $latestAt = $event->at;
                $group = ParcelStatus::next($group, $event->code);
                $values = [
                    'status_code' => $event->code,
                    'event_name' => mb_substr($event->name, 0, ParcelHistory::TEXT_LENGTH),
                    'event_at' => $event->at,
                    'status_group' => $group,
                ] + $values;
            }
            $values['history'] = ParcelHistory::encode($history);
        }
        if ($values === []) {
            return false;
        }
        $this->rows->saveEvent((int)$row->order_id, $values + ['checked_at' => $this->stamp()]);

        return true;
    }

    /** Посилки, чиї події вікна пропустили (простій довший за наздоганяння, збій Meest), перевіряються поодинці. */
    private function pollSafety(): void
    {
        $now = ($this->now)();
        $due = $this->rows->dueForSafety(
            self::SAFETY_LIMIT,
            date('Y-m-d H:i:s', $now - self::TRACK_FOR_DAYS * 86400),
            date('Y-m-d H:i:s', $now - self::SILENT_AFTER),
        );
        foreach ($due as $row) {
            $barcode = (string)$row->barcode;
            try {
                $items = $this->history($barcode);
                $this->auth->clear(AuthFlags::TRACKING);
            } catch (MeestApiException $e) {
                if (!self::onlyThisRequest($e)) {
                    throw $e;
                }
                $items = [];
            }
            $events = TrackingAnswer::events($items, $barcode);
            // Рядок позначається перевіреним і без подій: інакше він щотіку стояв би першим і тримав решту.
            if ($events === [] || !$this->record($row, $events)) {
                $this->rows->markChecked((int)$row->order_id, $this->stamp());
            }
        }
    }

    /**
     * @return list<array<mixed>>
     * @throws MeestApiException
     */
    private function history(string $barcode): array
    {
        return MeestClient::rows($this->client->call('GET', 'tracking/' . rawurlencode($barcode)));
    }

    /** Відмова саме цього запиту: повтор дасть те саме, а інші запити тіку можуть пройти. */
    private static function onlyThisRequest(MeestApiException $e): bool
    {
        return in_array($e->kind, [ApiErrorKind::Rejected, ApiErrorKind::Malformed, ApiErrorKind::Empty, ApiErrorKind::NotFound], true);
    }

    /** Код поза таблицею груп іде в лог один раз, щоб таблицю можна було доповнити, а лог не забивався щотіку. */
    private function noteUnknown(int $code): void
    {
        if (ParcelStatus::group($code) !== null) {
            return;
        }
        if ($this->unknownCodes === null) {
            $stored = json_decode($this->state->get(self::UNKNOWN_CODES) ?? '[]', true);
            $this->unknownCodes = is_array($stored) ? array_values(array_filter($stored, is_int(...))) : [];
        }
        if (in_array($code, $this->unknownCodes, true)) {
            return;
        }
        $this->logger->warning("Meest tracking: unknown event code {$code}");
        $this->unknownCodes = array_slice([...$this->unknownCodes, $code], -self::UNKNOWN_CODES_KEPT);
        $this->state->set(self::UNKNOWN_CODES, (string)json_encode($this->unknownCodes));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stamp(): string
    {
        return date('Y-m-d H:i:s', ($this->now)());
    }
}
