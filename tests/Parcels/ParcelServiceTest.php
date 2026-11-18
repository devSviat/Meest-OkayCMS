<?php

namespace Modules\Sviat\Meest\Parcels;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\TransportException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Parcels\ParcelConfig;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDetails;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use Okay\Modules\Sviat\Meest\Parcels\ParcelHistory;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssue;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelOutcome;
use Okay\Modules\Sviat\Meest\Parcels\ParcelService;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Відповідь `POST /parcel` за openAPI_ua.json: result{parcelID, barCode, costServices, estimatedDeliveryDate (dd.MM.yyyy), …}.
 * Видалення — `DELETE /parcel/{parcelID}/{contractID}`, result порожній. `GET /tracking/{trackNumber}` — result-масив
 * подій {barCode, eventDateTime (`2019-05-02 11:21:42`), eventCode, eventDescr{descrUA}, eventCityDescr{descrUA}}.
 */
class ParcelServiceTest extends TestCase
{
    private const int ORDER = 1042;
    private const string CONTRACT = 'dddddddd-0000-4000-8000-00000000000b';
    private const string OLD_CONTRACT = 'dddddddd-0000-4000-8000-00000000000a';
    private const string PARCEL_ID = 'ffffffff-0000-4000-8000-000000000001';
    private const string BARCODE = '711-2550636';
    private const string RECEIVER = 'Тестенко Тест';
    private const string PHONE = '+380671234567';

    private FakeTransport $transport;
    private InMemoryParcelRows $rows;
    private ArrayLogger $logger;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryParcelRows();
        $this->logger = new ArrayLogger();
    }

    private function service(): ParcelService
    {
        $credentials = new FixedCredentials(new Credentials('cabinet-token-abc', self::CONTRACT));
        $state = new InMemoryState();
        $now = fn(): int => $this->now;
        $client = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $state, new AuthFlags($state), $now),
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            $this->logger,
        );

        return new ParcelService($client, $this->rows, $credentials, new ParcelIssues(), $this->logger, $now);
    }

    private static function draft(string $name = self::RECEIVER, string $phone = self::PHONE): ParcelDraft
    {
        $branch = (object)[
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'weight_place_max' => '30.00', 'weight_total_max' => '30.00',
            'insurance_max' => '50000.00', 'length_max' => 120, 'width_max' => 60, 'height_max' => 60, 'sending_only' => 0,
        ];

        return new ParcelDraft($name, $phone, $branch, 2.5, 30, 20, 15, 1000.0, true, 'noncash', 'Одяг', '1042');
    }

    private static function config(): ParcelConfig
    {
        $stored = [
            ShippingConfig::SENDER_NAME => 'Магазин Тестовий',
            ShippingConfig::SENDER_PHONE => '0501112233',
            ShippingConfig::SENDER_BRANCH_ID => 'cccccccc-0000-4000-8000-000000000003',
            Credentials::CONTRACT_ID => self::CONTRACT,
        ];

        return ParcelConfig::fromSettings(static fn(string $key): mixed => $stored[$key] ?? null);
    }

    private function ok(mixed $result): void
    {
        $this->transport->json(200, ['status' => 'OK', 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => ''], 'result' => $result]);
    }

    private function created(array $over = []): void
    {
        $this->ok($over + [
            'parcelID' => self::PARCEL_ID,
            'barCode' => self::BARCODE,
            'costServices' => 512.72,
            'estimatedDeliveryDate' => '29.11.2026',
            'receiverName' => 'Не з відповіді',
        ]);
    }

    /** @return list<string> «METHOD шлях» без хоста */
    private function calls(): array
    {
        return array_map(
            static fn($r): string => $r->method . ' ' . substr($r->url, strlen(Credentials::BASE)),
            $this->transport->requests(),
        );
    }

    private function posts(): int
    {
        return count(array_filter($this->calls(), static fn(string $c): bool => $c === 'POST parcel'));
    }

    /** @param array<string, mixed> $over */
    private function createdRow(array $over = []): object
    {
        $number = $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->now));
        self::assertNotNull($number);
        $this->rows->complete(self::ORDER, ['parcel_id' => self::PARCEL_ID, 'barcode' => self::BARCODE, 'contract_id' => self::OLD_CONTRACT]);
        $row = $this->rows->forOrder(self::ORDER);
        foreach ($over as $field => $value) {
            $row->{$field} = $value;
        }

        return $row;
    }

    /** @return list<string> */
    private static function texts(ParcelOutcome $outcome): array
    {
        return array_map(static fn(ParcelIssue $issue): string => $issue->text, $outcome->issues);
    }

    public function testCreateStoresParcel(): void
    {
        $this->created();

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertTrue($outcome->ok);
        self::assertSame(self::BARCODE, $outcome->barcode);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(PendingParcel::CREATED, $row->state);
        self::assertSame(self::PARCEL_ID, $row->parcel_id);
        self::assertSame(self::BARCODE, $row->barcode);
        self::assertSame(self::ORDER . '-' . $row->id, $row->parcel_number);
        self::assertSame(512.72, $row->delivery_price);
        self::assertSame(1000.0, $row->declared_value);
        self::assertSame(1, $row->receiver_pay);
        self::assertSame('noncash', $row->pay_type);
        self::assertSame(self::CONTRACT, $row->contract_id);
        self::assertSame('created', $row->status_group);
        self::assertNull($row->reserved_at);
        $details = ParcelDetails::decode($row->details);
        self::assertSame(self::RECEIVER, $details['receiver_name'], 'what was sent, not what came back');
        self::assertSame(self::PHONE, $details['receiver_phone']);
        self::assertSame(2.5, $details['weight_kg']);
        self::assertSame('2026-11-29', $details['delivery_date']);
        $body = json_decode((string)$this->transport->requests()[0]->body, true);
        self::assertSame($row->parcel_number, $body['parcelNumber']);
        self::assertSame((string)self::ORDER, $body['orderNumber']);
    }

    /** Подвійний клік чи дві вкладки: друга спроба не доходить до Meest. */
    public function testSecondCreateRefusedAndOnePost(): void
    {
        $this->created();
        $service = $this->service();

        $first = $service->create(self::ORDER, self::draft(), self::config());
        $second = $service->create(self::ORDER, self::draft(), self::config());

        self::assertTrue($first->ok);
        self::assertFalse($second->ok);
        self::assertSame(['sviat__meest__parcel_exists'], self::texts($second));
        self::assertSame(self::BARCODE, $second->value);
        self::assertSame(1, $this->posts());
    }

    /** Звичайний повтор відмовляє за наявним рядком і не пробує вставки: дубль ключа ядро пише в лог як помилку. */
    public function testRepeatCreateDoesNotTryToReserve(): void
    {
        $this->created();
        $service = $this->service();
        self::assertTrue($service->create(self::ORDER, self::draft(), self::config())->ok);

        $second = $service->create(self::ORDER, self::draft(), self::config());

        self::assertSame(['sviat__meest__parcel_exists'], self::texts($second));
        self::assertSame(1, $this->rows->reserveCalls);
    }

    public function testConcurrentReserveRefusedWithoutPost(): void
    {
        $this->rows->concurrentReserve = true;

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertSame(['sviat__meest__parcel_err_pending'], self::texts($outcome));
        self::assertSame(0, $this->posts());
    }

    public function testTimeoutAfterSendLeavesUncertainNoRetry(): void
    {
        $this->transport->push(new TransportException('Operation timed out after 20001 milliseconds'));
        $service = $this->service();

        $outcome = $service->create(self::ORDER, self::draft(), self::config());
        $again = $service->create(self::ORDER, self::draft(), self::config());

        self::assertFalse($outcome->ok);
        self::assertContains('sviat__meest__parcel_err_uncertain', self::texts($outcome));
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
        self::assertSame(['sviat__meest__parcel_err_uncertain'], self::texts($again));
        self::assertSame(1, $this->posts(), 'no automatic retry');
    }

    public function testServerErrorLeavesUncertain(): void
    {
        $this->transport->json(502, ['status' => 'Error']);

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertFalse($outcome->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function test2xxWithoutParcelIdIsUncertain(): void
    {
        $this->ok(['barCode' => self::BARCODE]);

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_uncertain'], self::texts($outcome));
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    /** Meest створила посилку, а запис не вдався: звільнене замовлення дало б створити другу. */
    public function testSaveFailureAfterCreateIsUncertain(): void
    {
        $this->created();
        $this->rows->failComplete = true;

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_not_saved'], self::texts($outcome));
        self::assertSame(self::BARCODE, $outcome->value);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testRejectedReleasesAndExplainsField(): void
    {
        $this->transport->json(400, ['status' => 'Error', 'info' => ['fieldName' => 'receiver.phone', 'message' => 'Невірний номер телефону', 'messageDetails' => '']]);

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertFalse($outcome->ok);
        self::assertNull($this->rows->forOrder(self::ORDER), 'nothing was created: the order is free again');
        self::assertSame('receiver_phone', $outcome->issues[0]->field);
        self::assertSame('Невірний номер телефону', $outcome->issues[0]->text);
    }

    public function testStatusErrorInEnvelopeReleases(): void
    {
        $this->transport->json(200, ['status' => 'Error', 'info' => ['fieldName' => 'payType', 'message' => 'payType is wrong']]);

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertFalse($outcome->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertSame('pay_type', $outcome->issues[0]->field);
    }

    /** Резерв не записався повністю: рядка немає, запит до Meest не йде, і відмова не каже, що посилка вже є. */
    public function testUnsavedReservationRefusedWithoutPost(): void
    {
        $this->rows->failReserve = true;

        $outcome = $this->service()->create(self::ORDER, self::draft(), self::config());

        self::assertSame(['sviat__meest__parcel_err_reserve'], self::texts($outcome));
        self::assertSame([], $this->transport->requests());
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testDraftErrorsRefusedWithoutReservation(): void
    {
        $outcome = $this->service()->create(self::ORDER, self::draft('Один'), self::config());

        self::assertFalse($outcome->ok);
        self::assertSame('receiver_name', $outcome->issues[0]->field);
        self::assertSame('sviat__meest__parcel_err_name', $outcome->issues[0]->text);
        self::assertSame([], $this->rows->rows);
        self::assertSame([], $this->transport->requests());
    }

    /** Процес, що зарезервував рядок, міг померти вже після POST: через 10 хвилин рядок невідомий, а не вільний. */
    public function testStaleReservationIsUncertain(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->now - PendingParcel::STALE_SECONDS + 1));
        $row = $this->rows->forOrder(self::ORDER);

        self::assertFalse(PendingParcel::needsCheck($row, $this->now));
        self::assertSame(['sviat__meest__parcel_err_pending'], self::texts($this->service()->create(self::ORDER, self::draft(), self::config())));

        $this->now += 1;
        self::assertTrue(PendingParcel::needsCheck($row, $this->now));
        self::assertSame(['sviat__meest__parcel_err_uncertain'], self::texts($this->service()->create(self::ORDER, self::draft(), self::config())));
        self::assertSame([], $this->transport->requests());
    }

    public function testRemoveUsesRowContract(): void
    {
        $this->createdRow(['status_code' => 2]);
        $this->ok([]);

        $outcome = $this->service()->remove(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame(['DELETE parcel/' . self::PARCEL_ID . '/' . self::OLD_CONTRACT], $this->calls());
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function notDeletable(): iterable
    {
        yield 'accepted by Meest' => [['status_code' => 1213]];
        yield 'attached by barcode' => [['parcel_id' => null]];
        yield 'in transit' => [['status_group' => 'in_transit']];
    }

    #[DataProvider('notDeletable')]
    public function testRemoveOnlyStatusTwoWithParcelId(array $over): void
    {
        $row = $this->createdRow($over);

        $outcome = $this->service()->remove(self::ORDER);

        self::assertFalse(ParcelService::deletable($row));
        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_not_deletable'], self::texts($outcome));
        self::assertSame([], $this->transport->requests());
        self::assertNotNull($this->rows->forOrder(self::ORDER));
    }

    public function testDeletableStates(): void
    {
        $row = $this->createdRow();
        self::assertTrue(ParcelService::deletable($row));
        $row->status_code = '2';
        self::assertTrue(ParcelService::deletable($row), 'the database returns numbers as strings');
        self::assertFalse(ParcelService::deletable((object)['state' => PendingParcel::UNCERTAIN, 'parcel_id' => self::PARCEL_ID, 'status_code' => null, 'status_group' => null]));
    }

    /** Статус змінився між показом картки й кліком: Meest відмовляє, рядок лишається. */
    public function testDeleteRefusedByMeestKeepsRow(): void
    {
        $this->createdRow(['status_code' => 2]);
        $this->transport->json(400, ['status' => 'Error', 'info' => ['fieldName' => '', 'message' => 'Parcel status does not allow deletion']]);

        $outcome = $this->service()->remove(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_delete_refused', 'Parcel status does not allow deletion'], self::texts($outcome));
        self::assertNotNull($this->rows->forOrder(self::ORDER));
    }

    public function testDeleteOutageKeepsRow(): void
    {
        $this->createdRow();
        $this->transport->push(new TransportException('Could not resolve host'));

        $outcome = $this->service()->remove(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_api_unreachable'], self::texts($outcome));
        self::assertNotNull($this->rows->forOrder(self::ORDER));
    }

    public function testRemoveAndUnlinkFreeTheOrder(): void
    {
        $service = $this->service();
        $numbers = [];
        foreach (['remove', 'unlink'] as $action) {
            $this->created();
            self::assertTrue($service->create(self::ORDER, self::draft(), self::config())->ok);
            $numbers[] = $this->rows->forOrder(self::ORDER)->parcel_number;
            if ($action === 'remove') {
                $this->ok([]);
            }

            self::assertTrue($service->{$action}(self::ORDER)->ok, $action);
            self::assertNull($this->rows->forOrder(self::ORDER), $action);
        }
        $this->created(['parcelID' => 'ffffffff-0000-4000-8000-000000000002', 'barCode' => '711-2550637']);
        self::assertTrue($service->create(self::ORDER, self::draft(), self::config())->ok);
        $numbers[] = $this->rows->forOrder(self::ORDER)->parcel_number;

        self::assertCount(3, array_unique($numbers), 'every attempt has its own parcelNumber');
        self::assertSame(3, $this->posts());
    }

    public function testUnlinkWaitsForRunningCreate(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->now));

        self::assertSame(['sviat__meest__parcel_err_pending'], self::texts($this->service()->unlink(self::ORDER)));
        self::assertNotNull($this->rows->forOrder(self::ORDER));
    }

    public function testAttachRefusesBarcodeOfAnotherOrder(): void
    {
        $this->createdRow();

        $outcome = $this->service()->attach(2077, '711-2550636');

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_barcode_elsewhere'], self::texts($outcome));
        self::assertSame((string)self::ORDER, $outcome->value);
        self::assertSame([], $this->transport->requests());
    }

    /** Штрихкод з SMS без дефіса: збіг з іншим замовленням видно лише після того, як Meest повернула його форму. */
    public function testAttachRefusesTypedBarcodeOfAnotherOrder(): void
    {
        $this->createdRow();
        $this->ok([['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']]]);

        $outcome = $this->service()->attach(2077, ' 711 2550636 ');

        self::assertSame(['sviat__meest__parcel_err_barcode_elsewhere'], self::texts($outcome));
        self::assertSame((string)self::ORDER, $outcome->value);
        self::assertNull($this->rows->forOrder(2077));
    }

    /** Створена посилка зберігає форму Meest із дефісом; та сама з SMS без дефіса не прив'язується до іншого замовлення. */
    public function testAttachRefusesDashlessFormOfCreatedParcel(): void
    {
        $this->created();
        self::assertTrue($this->service()->create(self::ORDER, self::draft(), self::config())->ok);
        $this->ok([['barCode' => '7112550636', 'eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']]]);

        $outcome = $this->service()->attach(2077, ' 711 2550636 ');

        self::assertSame(['sviat__meest__parcel_err_barcode_elsewhere'], self::texts($outcome));
        self::assertSame((string)self::ORDER, $outcome->value);
        self::assertNull($this->rows->forOrder(2077));
        self::assertSame(['POST parcel'], $this->calls(), 'refused before asking Meest');
    }

    public function testAttachNotFoundIsNotOurs(): void
    {
        $this->transport->json(404, ['status' => 'Error', 'info' => ['message' => 'Not found']]);

        $outcome = $this->service()->attach(self::ORDER, self::BARCODE);

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_not_ours'], self::texts($outcome));
        self::assertSame(['GET tracking/' . self::BARCODE], $this->calls());
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testAttachStoresLastEvent(): void
    {
        $this->ok([
            ['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 18:05:00', 'eventCode' => 1100, 'eventDescr' => ['descrUA' => 'Відправлено']],
            ['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']],
        ]);

        $outcome = $this->service()->attach(self::ORDER, '711 2550636');

        self::assertTrue($outcome->ok);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(PendingParcel::CREATED, $row->state);
        self::assertNull($row->parcel_id);
        self::assertNull($row->receiver_pay);
        self::assertSame(self::BARCODE, $row->barcode);
        self::assertSame(self::CONTRACT, $row->contract_id);
        self::assertSame(date('Y-m-d H:i:s', $this->now), $row->created_at, 'the service clock, not the wall clock');
        self::assertSame(1100, $row->status_code);
        self::assertSame('Відправлено', $row->event_name);
        $kyiv = new \DateTimeImmutable('2026-11-16 18:05:00', new \DateTimeZone('Europe/Kyiv'));
        self::assertSame($kyiv->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'), $row->event_at);
    }

    /** Прив'язка бачить повну історію з відповіді `tracking/{barCode}`: з неї і група, і час прибуття. */
    public function testAttachStoresHistoryAndGroup(): void
    {
        $this->ok([
            ['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 18:05:00', 'eventCode' => 1214, 'eventDescr' => ['descrUA' => 'Прибуло'], 'eventCityDescr' => ['descrUA' => 'Львів']],
            ['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']],
            ['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 12:00:00', 'eventCode' => 9999, 'eventDescr' => ['descrUA' => 'Невідома']],
            ['barCode' => self::BARCODE, 'eventDateTime' => 'зіпсовано', 'eventCode' => 1100],
        ]);

        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);

        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame('arrived', $row->status_group);
        self::assertSame(1214, $row->status_code);
        $history = ParcelHistory::decode($row->history);
        self::assertSame([2, 9999, 1214], array_column($history, 'code'));
        self::assertSame('Львів', $history[2]['place']);
        self::assertSame($history[2]['at'], $row->arrived_at);
        self::assertSame($history[2]['at'], $row->event_at);
    }

    /** Відповідь могла містити події іншої посилки; дефіси в штрихкоді Meest не роблять її чужою. */
    public function testAttachIgnoresEventsOfOtherBarcodes(): void
    {
        $this->ok([
            ['barCode' => '7112550636', 'eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']],
            ['barCode' => '711-2550699', 'eventDateTime' => '2026-11-16 18:00:00', 'eventCode' => 1622, 'eventDescr' => ['descrUA' => 'Чуже']],
        ]);

        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);

        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(2, $row->status_code);
        self::assertSame('created', $row->status_group);
        self::assertSame([2], array_column(ParcelHistory::decode($row->history), 'code'));
    }

    /** Без жодної прочитаної події посилка лише створена, як і після create(): група не порожня, бейдж і фільтри її бачать. */
    public function testAttachWithoutReadableEventsIsCreated(): void
    {
        $this->ok([['barCode' => self::BARCODE, 'eventDateTime' => 'зіпсовано', 'eventCode' => 1100]]);

        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);

        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame('created', $row->status_group);
        self::assertNull($row->status_code);
    }

    public function testAttachAttributesItemsWithoutBarcode(): void
    {
        $this->ok([
            ['eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']],
            ['вarCode' => null, 'eventDateTime' => '2026-11-16 18:00:00', 'eventCode' => 1100, 'eventDescr' => ['descrUA' => 'Відправлено']],
        ]);

        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);

        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(self::BARCODE, $row->barcode);
        self::assertSame(1100, $row->status_code);
        self::assertSame('in_transit', $row->status_group);
        self::assertSame([2, 1100], array_column(ParcelHistory::decode($row->history), 'code'));
    }

    /** Невідомий результат розбирається прив'язкою; рядок, де створення ще йде, — ні. */
    public function testAttachOnlyToFreeOrUncertainOrder(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->now));
        self::assertSame(['sviat__meest__parcel_err_pending'], self::texts($this->service()->attach(self::ORDER, self::BARCODE)));

        $this->rows->markUncertain(self::ORDER);
        $this->ok([['barCode' => self::BARCODE, 'eventDateTime' => '2026-11-16 10:00:00', 'eventCode' => 2, 'eventDescr' => ['descrUA' => 'Створено']]]);
        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);
        self::assertSame(PendingParcel::CREATED, $this->rows->forOrder(self::ORDER)->state);

        self::assertSame(['sviat__meest__parcel_exists'], self::texts($this->service()->attach(self::ORDER, '711-2550637')));
        self::assertCount(1, $this->transport->requests());
    }

    public function testAttachRejectsUnreadableBarcode(): void
    {
        self::assertSame(['sviat__meest__parcel_err_barcode'], self::texts($this->service()->attach(self::ORDER, '../print')));
        self::assertSame([], $this->transport->requests());
    }

    public function testReceiverDataNeverLogged(): void
    {
        $this->transport->json(400, ['status' => 'Error', 'info' => ['fieldName' => 'receiver.name', 'message' => 'Bad name ' . self::RECEIVER . ' ' . self::PHONE]]);
        $this->transport->push(new TransportException('timeout'));
        $service = $this->service();

        $service->create(self::ORDER, self::draft(), self::config());
        $service->create(self::ORDER, self::draft(), self::config());
        $this->rows->rows = [];
        $this->created();
        $service->create(self::ORDER, self::draft(), self::config());

        self::assertNotSame('', $this->logger->text());
        foreach ([self::RECEIVER, 'Тестенко', self::PHONE, '671234567', 'Одяг'] as $secret) {
            self::assertStringNotContainsString($secret, $this->logger->text());
        }
    }
}
