<?php

namespace Modules\Sviat\Meest\Parcels;

require_once __DIR__ . '/../Support/CallbackTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/../Support/RecordingInit.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\CallbackTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Modules\Sviat\Meest\Support\InMemoryState;
use Modules\Sviat\Meest\Support\RecordingInit;
use Okay\Core\Scheduler\Schedule;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Parcels\ParcelHistory;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelTracking;
use Okay\Modules\Sviat\Meest\Parcels\TrackingCursor;
use PHPUnit\Framework\TestCase;

/**
 * Фейкова Meest відповідає за станом подій: `trackingByPeriod` віддає події, чий київський час у вікні [from, to),
 * `tracking/{barCode}` — усі події посилки або 404. Подія з `visible` з'являється в API лише з цього моменту.
 */
class ParcelTrackingTest extends TestCase
{
    private const int ORDER = 1042;
    private const string BARCODE = '711-2550636';
    private const string CONTRACT = 'dddddddd-0000-4000-8000-00000000000b';

    private CallbackTransport $transport;
    private InMemoryParcelRows $rows;
    private InMemoryState $state;
    private InMemoryMutex $mutex;
    private ArrayLogger $logger;
    private int $now;
    /** @var list<array{event: array<string, mixed>, visible: int}> */
    private array $world = [];
    /** @var list<array{0: string, 1: HttpResponse}> */
    private array $failures = [];

    protected function setUp(): void
    {
        $this->now = self::ts('2026-11-16T12:00:00Z');
        $this->transport = new CallbackTransport($this->meest(...));
        $this->rows = new InMemoryParcelRows();
        $this->state = new InMemoryState();
        $this->mutex = new InMemoryMutex();
        $this->logger = new ArrayLogger();
    }

    private function tracking(): ParcelTracking
    {
        $credentials = new FixedCredentials(new Credentials('cabinet-token-abc', self::CONTRACT));
        $now = fn(): int => $this->now;
        $client = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $this->state, new AuthFlags($this->state), $now),
            new RequestBudget($this->state, new InMemoryMutex(), $now),
            $credentials,
            $this->logger,
        );

        return new ParcelTracking(
            $client,
            $this->rows,
            new TrackingCursor($this->state, $now),
            $this->mutex,
            new AuthFlags($this->state),
            $credentials,
            $this->state,
            new ParcelIssues(),
            $this->logger,
            $now,
        );
    }

    private function meest(string $path): HttpResponse
    {
        foreach ($this->failures as $i => [$prefix, $response]) {
            if (str_starts_with($path, $prefix)) {
                unset($this->failures[$i]);

                return $response;
            }
        }
        $visible = array_values(array_filter($this->world, fn(array $e): bool => $e['visible'] <= $this->now));
        if (preg_match('#^trackingByPeriod/([^/]+)/([^/]+)$#', $path, $m) === 1) {
            $from = self::kyivTs(rawurldecode($m[1]));
            $to = self::kyivTs(rawurldecode($m[2]));
            $found = array_filter($visible, static function (array $e) use ($from, $to): bool {
                $at = self::kyivTs($e['event']['eventDateTime']);

                return $at >= $from && $at < $to;
            });

            return self::ok(array_column($found, 'event'));
        }
        if (preg_match('#^tracking/([^/]+)$#', $path, $m) === 1) {
            $key = str_replace('-', '', rawurldecode($m[1]));
            $found = array_filter($visible, static fn(array $e): bool => str_replace('-', '', $e['event']['barCode']) === $key);

            return $found === []
                ? new HttpResponse(404, (string)json_encode(['status' => 'Error', 'info' => ['message' => 'Not found']]))
                : self::ok(array_column($found, 'event'));
        }

        throw new \LogicException("Unexpected request: {$path}");
    }

    /** @param list<array<string, mixed>> $result */
    private static function ok(array $result): HttpResponse
    {
        return new HttpResponse(200, (string)json_encode(['status' => 'OK', 'info' => [], 'result' => array_values($result)], JSON_UNESCAPED_UNICODE));
    }

    private function refuse(string $prefix, int $status): void
    {
        $this->failures[] = [$prefix, new HttpResponse($status, (string)json_encode(['status' => 'Error', 'info' => ['message' => 'refused']]))];
    }

    /** Подія в стані Meest; `$kyiv` — київський настінний час, як його пише Meest. */
    private function event(string $barcode, int $code, string $kyiv, ?int $visible = null, string $name = 'Подія'): void
    {
        $this->world[] = [
            'event' => [
                'parcelNumber' => '1042-1',
                'barCode' => $barcode,
                'eventDateTime' => $kyiv,
                'eventCode' => $code,
                'eventCodeUPU' => '',
                'eventDescr' => ['descrUA' => $name, 'descrRU' => '', 'descrEN' => ''],
                'eventCityDescr' => ['descrUA' => 'Київ', 'descrRU' => '', 'descrEN' => ''],
            ],
            'visible' => $visible ?? 0,
        ];
    }

    /** @param array<string, mixed> $over */
    private function parcel(int $orderId, string $barcode, array $over = []): void
    {
        self::assertTrue($this->rows->attach($orderId, $over + [
            'barcode' => $barcode,
            'parcel_id' => null,
            'created_at' => $this->stamp($this->now - 86400),
            'status_code' => 2,
            'status_group' => 'created',
            'checked_at' => $this->stamp($this->now - 600),
        ]));
    }

    private function cursorAtEdge(): void
    {
        $this->state->set(TrackingCursor::STATE, (string)($this->now - TrackingCursor::LAG));
    }

    private function row(int $orderId = self::ORDER): object
    {
        $row = $this->rows->forOrder($orderId);
        self::assertNotNull($row);

        return $row;
    }

    private function stamp(int $at): string
    {
        return date('Y-m-d H:i:s', $at);
    }

    private static function ts(string $utc): int
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))->getTimestamp();
    }

    private static function kyivTs(string $wall): int
    {
        return new \DateTimeImmutable($wall, new \DateTimeZone('Europe/Kyiv'))->getTimestamp();
    }

    /** Київський час події в поясі застосунку, як його зберігає рядок. */
    private static function app(string $kyiv): string
    {
        return date('Y-m-d H:i:s', self::kyivTs($kyiv));
    }

    /** @return list<int> */
    private function historyCodes(int $orderId = self::ORDER): array
    {
        return array_column(ParcelHistory::decode($this->row($orderId)->history), 'code');
    }

    public function testCursorStaysAfterFailedWindow(): void
    {
        $cursor = $this->now - TrackingCursor::LAG - 4 * 3600;
        $this->state->set(TrackingCursor::STATE, (string)$cursor);
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event(self::BARCODE, 1100, '2026-11-16 13:00:00');
        $this->refuse('trackingByPeriod/', 400);

        $this->tracking()->tick();

        self::assertSame((string)$cursor, $this->state->get(TrackingCursor::STATE));
        // Невдале вікно зупиняє наздоганяння, а перечитування йде: подію останніх годин записано.
        self::assertCount(1 + 3, $this->transport->pathsStartingWith('trackingByPeriod/'));
        self::assertSame(1100, $this->row()->status_code);
    }

    public function testUnavailableMeestStopsTickAndKeepsCursor(): void
    {
        $cursor = $this->now - TrackingCursor::LAG - 4 * 3600;
        $this->state->set(TrackingCursor::STATE, (string)$cursor);
        $this->parcel(self::ORDER, self::BARCODE, ['checked_at' => null]);
        $this->refuse('trackingByPeriod/', 503);

        $this->tracking()->tick();

        self::assertSame((string)$cursor, $this->state->get(TrackingCursor::STATE));
        self::assertCount(1, $this->transport->paths, 'other windows and safety would wait on the same outage');
    }

    public function testCatchUpAdvancesCursorWindowByWindow(): void
    {
        $cursor = $this->now - TrackingCursor::LAG - 4 * 3600;
        $this->state->set(TrackingCursor::STATE, (string)$cursor);

        $this->tracking()->tick();

        self::assertSame((string)($this->now - TrackingCursor::LAG), $this->state->get(TrackingCursor::STATE));
        self::assertCount(2 + 3, $this->transport->pathsStartingWith('trackingByPeriod/'));
        self::assertSame([30], array_values(array_unique($this->transport->timeouts)), 'one window must not eat the 600 s tick');
    }

    /** Порожнє вікно Meest може віддати 404 (як parcelsList на порожній день): курсор не застрягає на тихій ночі. */
    public function testNotFoundWindowIsEmpty(): void
    {
        $cursor = $this->now - TrackingCursor::LAG - 2 * 3600;
        $this->state->set(TrackingCursor::STATE, (string)$cursor);
        $this->refuse('trackingByPeriod/', 404);

        $this->tracking()->tick();

        self::assertSame((string)($this->now - TrackingCursor::LAG), $this->state->get(TrackingCursor::STATE));
    }

    public function testOverlapDoesNotRollBack(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE, [
            'status_code' => 1213,
            'status_group' => 'arrived',
            'event_name' => 'Прибуло',
            'event_at' => self::app('2026-11-16 10:00:00'),
            'arrived_at' => self::app('2026-11-16 10:00:00'),
        ]);
        $this->event(self::BARCODE, 606, '2026-11-16 09:00:00');

        $this->tracking()->tick();

        $row = $this->row();
        self::assertSame(1213, $row->status_code);
        self::assertSame('arrived', $row->status_group);
        self::assertSame('Прибуло', $row->event_name);
        self::assertSame(self::app('2026-11-16 10:00:00'), $row->event_at);
        self::assertContains(606, $this->historyCodes(), 'the older event still belongs to the history');
    }

    public function testLateEventCaughtByReread(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        // Час події на годину раніший за кінець вікна першого тіку, а в API вона з'являється лише до другого.
        $edge = $this->now - TrackingCursor::LAG;
        $at = new \DateTimeImmutable('@' . ($edge - 3600))->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d H:i:s');
        $this->event(self::BARCODE, 1100, $at, $this->now + 600);

        $this->tracking()->tick();
        self::assertSame(2, $this->row()->status_code);

        $this->now += 900;
        $this->tracking()->tick();

        self::assertSame(1100, $this->row()->status_code);
        self::assertSame('in_transit', $this->row()->status_group);
    }

    public function testDuplicateEventWrittenOnce(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event(self::BARCODE, 1100, '2026-11-16 12:00:00');
        $this->event(self::BARCODE, 1100, '2026-11-16 12:00:00');

        $this->tracking()->tick();
        $this->now += 900;
        $this->tracking()->tick();

        self::assertSame([1100], $this->historyCodes());
    }

    public function testOverlappingWindowsDoNotDuplicateHistory(): void
    {
        // Вікно наздоганяння й перечитування покривають ту саму годину.
        $this->state->set(TrackingCursor::STATE, (string)($this->now - TrackingCursor::LAG - 2 * 3600));
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event(self::BARCODE, 1100, '2026-11-16 12:30:00');
        $this->event(self::BARCODE, 1213, '2026-11-16 13:00:00');

        $this->tracking()->tick();

        self::assertSame([1100, 1213], $this->historyCodes());
        // Тік дописує історію з вікон: запиту історії на кожну подію немає.
        self::assertSame([], $this->transport->pathsStartingWith('tracking/'));
        self::assertCount(1 + 3, $this->transport->paths);
    }

    public function testHistoryCappedAtFifty(): void
    {
        $this->cursorAtEdge();
        $old = [];
        for ($i = 0; $i < ParcelHistory::MAX_EVENTS; $i++) {
            $old[] = ['code' => 1100, 'name' => 'Подія', 'place' => '', 'at' => $this->stamp($this->now - 86400 + $i * 60)];
        }
        $this->parcel(self::ORDER, self::BARCODE, ['history' => ParcelHistory::encode($old)]);
        $this->event(self::BARCODE, 1213, '2026-11-16 13:00:00');

        $this->tracking()->tick();

        $history = ParcelHistory::decode($this->row()->history);
        self::assertCount(ParcelHistory::MAX_EVENTS, $history);
        self::assertSame(1213, $history[array_key_last($history)]['code']);
        self::assertSame($old[1]['at'], $history[0]['at'], 'the oldest entry is dropped');
    }

    public function testUnknownParcelsIgnored(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event('CV999999999UA', 1622, '2026-11-16 12:00:00');

        $this->tracking()->tick();

        self::assertCount(1, $this->rows->rows);
        self::assertSame(2, $this->row()->status_code);
        self::assertNull($this->row()->history);
    }

    /** Рядок міг зберегти штрихкод без дефіса, а Meest пише його з дефісом, і навпаки. */
    public function testBarcodeMatchedIgnoringDashes(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, '7112550636');
        $this->parcel(2077, '711-2550637');
        $this->event(self::BARCODE, 1100, '2026-11-16 12:00:00');
        $this->event('7112550637', 1213, '2026-11-16 12:10:00');

        $this->tracking()->tick();

        self::assertSame(1100, $this->row()->status_code);
        self::assertSame(1213, $this->row(2077)->status_code);
    }

    public function testUnknownCodeLoggedOnce(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event(self::BARCODE, 9999, '2026-11-16 12:00:00');

        $this->tracking()->tick();
        $this->event(self::BARCODE, 9999, '2026-11-16 13:00:00', $this->now + 1);
        $this->now += 900;
        $this->tracking()->tick();

        self::assertSame(1, substr_count($this->logger->text(), '9999'));
        self::assertSame(9999, $this->row()->status_code, 'the event is written');
        self::assertSame('created', $this->row()->status_group, 'an unknown code keeps the group');
        self::assertSame([9999, 9999], $this->historyCodes());
    }

    public function testArrivedAtIsFirstArrival(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event(self::BARCODE, 1214, '2026-11-16 11:00:00');
        $this->event(self::BARCODE, 1213, '2026-11-16 10:00:00');
        $this->event(self::BARCODE, 1622, '2026-11-16 12:00:00');

        $this->tracking()->tick();

        $row = $this->row();
        self::assertSame(self::app('2026-11-16 10:00:00'), $row->arrived_at);
        self::assertSame('received', $row->status_group);
        self::assertSame(1622, $row->status_code);
    }

    public function testSafetyPollsSilentOnlyAndCapsAtThirty(): void
    {
        $this->cursorAtEdge();
        for ($i = 0; $i < 35; $i++) {
            $this->parcel(2000 + $i, sprintf('711-00000%02d', $i), ['checked_at' => $this->stamp($this->now - 86400 + $i)]);
            $this->event(sprintf('711-00000%02d', $i), 1100, '2026-11-14 12:00:00');
        }
        $this->parcel(3000, '711-0000999', ['checked_at' => $this->stamp($this->now - 3600)]);
        $this->parcel(3001, '711-0000998', ['checked_at' => null, 'event_at' => $this->stamp($this->now - 3600)]);

        $this->tracking()->tick();

        $polled = $this->transport->pathsStartingWith('tracking/');
        self::assertCount(ParcelTracking::SAFETY_LIMIT, $polled);
        self::assertSame('tracking/711-0000000', $polled[0], 'the longest unchecked first');
        self::assertNotContains('tracking/711-0000999', $polled);
        self::assertNotContains('tracking/711-0000998', $polled);
        self::assertSame($this->stamp($this->now), $this->row(2000)->checked_at);
        self::assertSame(1100, $this->row(2000)->status_code);
        self::assertSame(2, $this->row(2034)->status_code, 'beyond the cap waits for the next tick');
    }

    /** Безуспішна страховка теж позначає рядок перевіреним: інакше він щотіку стояв би першим. */
    public function testSafetyNotFoundMarksChecked(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE, ['checked_at' => null]);

        $this->tracking()->tick();

        self::assertSame(['tracking/' . self::BARCODE], $this->transport->pathsStartingWith('tracking/'));
        self::assertSame($this->stamp($this->now), $this->row()->checked_at);
        self::assertSame(2, $this->row()->status_code);
    }

    public function testOlderThanSixtyDaysNotPolled(): void
    {
        $this->cursorAtEdge();
        $this->parcel(2000, '711-0000001', ['checked_at' => null, 'created_at' => $this->stamp($this->now - 61 * 86400)]);
        $this->parcel(2001, '711-0000002', ['checked_at' => null, 'created_at' => $this->stamp($this->now - 59 * 86400)]);
        $this->event('711-0000002', 1100, '2026-11-01 12:00:00');

        $this->tracking()->tick();

        self::assertSame(['tracking/711-0000002'], $this->transport->pathsStartingWith('tracking/'));
    }

    public function testAuthFailureStopsAndFlags(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE, ['checked_at' => null]);
        $this->refuse('trackingByPeriod/', 401);

        $this->tracking()->tick();

        self::assertSame('bad_token', new AuthFlags($this->state)->reason(AuthFlags::TRACKING));
        self::assertCount(1, $this->transport->paths);
    }

    public function testRateLimitStopsTick(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE, ['checked_at' => null]);
        $this->refuse('trackingByPeriod/', 429);

        $this->tracking()->tick();

        self::assertCount(1, $this->transport->paths);
        self::assertNull(new AuthFlags($this->state)->reason(AuthFlags::TRACKING));
    }

    public function testSuccessClearsTrackingFlag(): void
    {
        $this->cursorAtEdge();
        $this->state->set('tracking_auth_failed', 'bad_token');

        $this->tracking()->tick();

        self::assertNull(new AuthFlags($this->state)->reason(AuthFlags::TRACKING));
    }

    public function testHeldMutexSkipsTick(): void
    {
        $this->mutex->held['track'] = true;

        $this->tracking()->tick();

        self::assertSame([], $this->transport->paths);
    }

    public function testCatchUpSpreadsOverTicks(): void
    {
        $start = $this->now - 72 * 3600;
        $this->state->set(TrackingCursor::STATE, (string)$start);

        $this->tracking()->tick();

        self::assertCount(TrackingCursor::MAX_CATCH_UP + 3, $this->transport->paths);
        self::assertSame((string)($start + 48 * 3600), $this->state->get(TrackingCursor::STATE));

        $this->transport->paths = [];
        $this->now += 900;
        $this->tracking()->tick();

        // Лишилося 23 год 45 хв: 11 повних вікон і одне коротше, потім ті самі 3 вікна перечитування.
        self::assertCount(12 + 3, $this->transport->paths);
        self::assertSame((string)($this->now - TrackingCursor::LAG), $this->state->get(TrackingCursor::STATE));
    }

    /** Модуль не змінює статусів замовлень: трекінг не має доступу до сутностей ядра. */
    public function testOrderStatusesUntouched(): void
    {
        $constructor = new \ReflectionMethod(ParcelTracking::class, '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            $type = (string)$parameter->getType();
            self::assertTrue(
                $type === 'Closure' || str_starts_with($type, 'Okay\\Modules\\Sviat\\Meest\\') || $type === 'Psr\\Log\\LoggerInterface',
                "ParcelTracking depends on {$type}",
            );
        }
        $source = (string)file_get_contents((string)new \ReflectionClass(ParcelTracking::class)->getFileName());
        self::assertDoesNotMatchRegularExpression('/OrdersEntity|EntityFactory|OrdersHelper|status_id/', $source);

        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        $this->event(self::BARCODE, 1622, '2026-11-16 12:00:00');
        $this->tracking()->tick();
        self::assertSame('received', $this->row()->status_group);
    }

    public function testRefreshReplacesHistory(): void
    {
        $stale = [['code' => 1100, 'name' => 'Застаріле', 'place' => '', 'at' => $this->stamp($this->now - 7200)]];
        $this->parcel(self::ORDER, self::BARCODE, ['history' => ParcelHistory::encode($stale)]);
        $this->event(self::BARCODE, 2, '2026-11-15 10:00:00');
        $this->event(self::BARCODE, 1213, '2026-11-16 11:00:00', null, 'Прибуло у відділення');
        $this->event(self::BARCODE, 1100, '2026-11-15 18:00:00');

        $outcome = $this->tracking()->refresh(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame(self::BARCODE, $outcome->barcode);
        self::assertSame([2, 1100, 1213], $this->historyCodes());
        $row = $this->row();
        self::assertSame(1213, $row->status_code);
        self::assertSame('Прибуло у відділення', $row->event_name);
        self::assertSame('arrived', $row->status_group);
        self::assertSame(self::app('2026-11-16 11:00:00'), $row->arrived_at);
        self::assertSame($this->stamp($this->now), $row->checked_at);
        self::assertSame(['tracking/' . self::BARCODE], $this->transport->paths);
    }

    /** `tracking/{barCode}` уже відібрано за штрихкодом: подія без поля штрихкоду належить цій посилці. */
    public function testRefreshAttributesItemsWithoutBarcode(): void
    {
        $this->parcel(self::ORDER, self::BARCODE);
        $this->failures[] = ['tracking/', self::ok([
            ['eventDateTime' => '2026-11-15 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']],
            ['barCode' => '', 'eventDateTime' => '2026-11-16 11:00:00', 'eventCode' => 1213, 'eventDescr' => ['descrUA' => 'Прибуло']],
        ])];

        self::assertTrue($this->tracking()->refresh(self::ORDER)->ok);

        self::assertSame([2, 1213], $this->historyCodes());
        self::assertSame(1213, $this->row()->status_code);
        self::assertSame('arrived', $this->row()->status_group);
    }

    public function testSafetyAttributesItemsWithoutBarcode(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE, ['checked_at' => null]);
        $this->failures[] = ['tracking/', self::ok([['eventDateTime' => '2026-11-14 10:00:00', 'eventCode' => 1100]])];

        $this->tracking()->tick();

        self::assertSame(1100, $this->row()->status_code);
    }

    /** У вікні `trackingByPeriod` події всіх посилок договору: без штрихкоду подію нікому не приписати. */
    public function testWindowItemWithoutBarcodeIgnored(): void
    {
        $this->cursorAtEdge();
        $this->parcel(self::ORDER, self::BARCODE);
        $this->failures[] = ['trackingByPeriod/', self::ok([['eventDateTime' => '2026-11-16 12:00:00', 'eventCode' => 1622]])];

        $this->tracking()->tick();

        self::assertSame(2, $this->row()->status_code);
        self::assertNull($this->row()->history);
    }

    public function testRefreshCapsHistoryAtFifty(): void
    {
        $this->parcel(self::ORDER, self::BARCODE);
        $first = self::kyivTs('2026-11-10 00:00:00');
        for ($i = 0; $i < 60; $i++) {
            $kyiv = new \DateTimeImmutable('@' . ($first + $i * 60))->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d H:i:s');
            $this->event(self::BARCODE, 1100, $kyiv);
        }

        $this->tracking()->refresh(self::ORDER);

        $history = ParcelHistory::decode($this->row()->history);
        self::assertCount(ParcelHistory::MAX_EVENTS, $history);
        self::assertSame($this->stamp($first + 10 * 60), $history[0]['at'], 'the 50 newest');
    }

    public function testRefreshWithoutParcelRefused(): void
    {
        $outcome = $this->tracking()->refresh(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__meest__parcel_err_none', $outcome->issues[0]->text);
        self::assertSame([], $this->transport->paths);
    }

    public function testRefreshFailureKeepsRowAndExplains(): void
    {
        $this->parcel(self::ORDER, self::BARCODE, ['history' => ParcelHistory::encode([['code' => 2, 'name' => 'Створено', 'place' => '', 'at' => $this->stamp($this->now - 7200)]])]);
        $this->refuse('tracking/', 401);

        $outcome = $this->tracking()->refresh(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__meest__parcel_api_bad_token', $outcome->issues[0]->text);
        self::assertSame('bad_token', new AuthFlags($this->state)->reason(AuthFlags::TRACKING));
        self::assertSame([2], $this->historyCodes());
    }

    public function testScheduledEveryFifteenMinutesWithoutOverlap(): void
    {
        $found = [];
        foreach (RecordingInit::record() as [$kind, $schedule]) {
            if ($kind !== 'schedule' || !$schedule instanceof Schedule) {
                continue;
            }
            $read = static fn(string $property): mixed => new \ReflectionProperty(Schedule::class, $property)->getValue($schedule);
            if ($read('command') === [ParcelTracking::class, 'tick']) {
                $found[] = [$read('name'), $read('time'), $read('overlap'), $read('timeout')];
            }
        }

        self::assertSame([['Meest: tracking', '*/15 * * * *', false, 600]], $found);
    }
}
