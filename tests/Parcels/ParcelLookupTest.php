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
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDetails;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssue;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelLookup;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

/**
 * Схема openAPI_ua.json. `GET parcelsList/{dd.MM.yyyy}`: result-масив {parcelID, parcelNumber, вarCode (кирилична «в»),
 * registerID, registerType}; статусу, дати й одержувача в елементі немає. `GET parcelStatus/{parcelID}`: без конверта,
 * масив {status, statusCode (рядок), date (`2020-09-28T00:00:00`)}; видалено — statusCode 3.
 */
class ParcelLookupTest extends TestCase
{
    private const int ORDER = 1042;
    private const string CONTRACT = 'dddddddd-0000-4000-8000-00000000000b';
    private const string ID_A = 'ffffffff-0000-4000-8000-000000000001';
    private const string ID_B = 'ffffffff-0000-4000-8000-000000000003';

    private FakeTransport $transport;
    private InMemoryParcelRows $rows;
    private string $zone;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryParcelRows();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private function lookup(): ParcelLookup
    {
        $credentials = new FixedCredentials(new Credentials('cabinet-token-abc', self::CONTRACT));
        $state = new InMemoryState();
        $now = fn(): int => $this->now;
        $logger = new ArrayLogger();
        $client = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $state, new AuthFlags($state), $now),
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            $logger,
        );

        return new ParcelLookup($client, $this->rows, $credentials, new ParcelIssues(), $logger, $now);
    }

    /** Невідомий рядок замовлення; повертає його `parcelNumber`. */
    private function uncertain(int $orderId = self::ORDER, string $reservedAt = '2026-11-16 10:00:00'): string
    {
        $number = $this->rows->reserve($orderId, $reservedAt);
        $this->rows->markUncertain($orderId);

        return (string)$number;
    }

    /** @param list<array<string, mixed>> $items */
    private function list(array $items): void
    {
        $this->transport->json(200, ['status' => 'OK', 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => ''], 'result' => $items]);
    }

    private static function item(string $id, string $number, string $barcode): array
    {
        return ['parcelID' => $id, 'parcelNumber' => $number, 'вarCode' => $barcode, 'registerID' => 'eeeeeeee-0000-4000-8000-000000000001', 'registerType' => 'registerPickUP'];
    }

    private function statusAnswer(int $code): void
    {
        $this->transport->json(200, [['status' => 'Created', 'statusCode' => (string)$code, 'date' => '2026-11-16T10:00:05']]);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_map(
            static fn($r): string => $r->method . ' ' . substr($r->url, strlen(Credentials::BASE)),
            $this->transport->requests(),
        );
    }

    public function testFindsExactParcelNumber(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_B, '1042-99', '711-0000001'), self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);
        $this->statusAnswer(2);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame('711-2550636', $outcome->barcode);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(self::ID_A, $row->parcel_id);
        self::assertSame('711-2550636', $row->barcode);
        self::assertSame(self::CONTRACT, $row->contract_id);
        self::assertSame(PendingParcel::CREATED, $row->state);
        self::assertSame(['GET parcelsList/16.11.2026', 'GET parcelsList/17.11.2026', 'GET parcelStatus/' . self::ID_A], $this->calls());
    }

    public function testDetailsAfterLookup(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636') + ['receiverName' => 'Тестенко Тест', 'estimatedDeliveryDate' => '29.11.2026']]);
        $this->list([]);
        $this->statusAnswer(2);

        $this->lookup()->find(self::ORDER);

        $details = ParcelDetails::decode($this->rows->forOrder(self::ORDER)->details);
        self::assertSame('Тестенко Тест', $details['receiver_name']);
        self::assertSame('2026-11-29', $details['delivery_date']);
    }

    public function testDetailsAfterLookupWithRealShapeItem(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);
        $this->statusAnswer(2);

        self::assertTrue($this->lookup()->find(self::ORDER)->ok);
        self::assertSame([], ParcelDetails::decode($this->rows->forOrder(self::ORDER)->details));
    }

    public function testNotFoundOnNextDayIsEmptyDay(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->transport->json(404, ['status' => 'Error']);
        $this->statusAnswer(2);

        self::assertTrue($this->lookup()->find(self::ORDER)->ok);
    }

    public function testNotFoundOnBothDaysGivesNone(): void
    {
        $this->uncertain();
        $this->transport->json(404, ['status' => 'Error']);
        $this->transport->json(404, ['status' => 'Error']);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertSame(['sviat__meest__parcel_err_lookup_none'], array_map(static fn(ParcelIssue $i): string => $i->text, $outcome->issues));
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testItemWithoutValidBarcodeSkipped(): void
    {
        $number = $this->uncertain();
        $noCode = self::item(self::ID_A, $number, '711-2550636');
        unset($noCode['вarCode']);
        $this->list([$noCode, self::item(self::ID_B, $number, '../x')]);
        $this->list([]);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testParcelIdOfAnotherOrderIgnored(): void
    {
        $number = $this->uncertain();
        $this->rows->attach(2077, ['barcode' => '711-0000009', 'parcel_id' => self::ID_A]);
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);

        self::assertFalse($this->lookup()->find(self::ORDER)->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testTwoExactMatchesListedNotBound(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636'), self::item(self::ID_B, $number, '711-2550637')]);
        $this->list([]);
        $this->statusAnswer(2);
        $this->statusAnswer(2);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame([self::ID_A, self::ID_B], array_column($outcome->candidates, 'parcel_id'));
        self::assertSame(['711-2550636', '711-2550637'], array_column($outcome->candidates, 'barcode'));
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(PendingParcel::UNCERTAIN, $row->state);
        self::assertNull($row->parcel_id ?? null);
    }

    public function testDeletedMatchIgnored(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636'), self::item(self::ID_B, $number, '711-2550637')]);
        $this->list([]);
        $this->statusAnswer(3);
        $this->statusAnswer(2);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame(self::ID_B, $this->rows->forOrder(self::ORDER)->parcel_id);
    }

    public function testStatusCheckedOnlyForExactMatches(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_B, '1042-99', '711-0000001'), self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);
        $this->statusAnswer(2);

        $this->lookup()->find(self::ORDER);

        self::assertCount(1, array_filter($this->calls(), static fn(string $c): bool => str_starts_with($c, 'GET parcelStatus/')));
    }

    public function testSameOrderNumberOtherAttemptIgnored(): void
    {
        $this->rows->reserve(self::ORDER, '2026-11-16 09:00:00');
        $this->rows->release(self::ORDER);
        $number = $this->uncertain();
        $other = '1042-' . ((int)substr($number, 5) - 1);
        $this->list([self::item(self::ID_B, $other, '711-0000001')]);
        $this->list([]);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(['sviat__meest__parcel_err_lookup_none'], array_map(static fn(ParcelIssue $i): string => $i->text, $outcome->issues));
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testBarcodeOfAnotherOrderIgnored(): void
    {
        $number = $this->uncertain();
        $this->rows->attach(2077, ['barcode' => '711-2550636', 'parcel_id' => self::ID_B]);
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testTypoBarcodeFieldRead(): void
    {
        $number = $this->uncertain();
        $item = self::item(self::ID_A, $number, '711-2550636');
        self::assertArrayNotHasKey('barCode', $item);
        $this->list([$item]);
        $this->list([]);
        $this->statusAnswer(2);

        self::assertSame('711-2550636', $this->lookup()->find(self::ORDER)->barcode);
    }

    public function testDatesInKyivAcrossMidnightAndDst(): void
    {
        $this->uncertain(reservedAt: '2026-03-28 23:50:00');
        $this->list([]);
        $this->list([]);

        $this->lookup()->find(self::ORDER);

        self::assertSame(['GET parcelsList/29.03.2026', 'GET parcelsList/30.03.2026'], $this->calls());
    }

    public function testChooseRechecksCandidate(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636'), self::item(self::ID_B, $number, '711-2550637')]);
        $this->list([]);
        $this->statusAnswer(2);
        $this->statusAnswer(2);
        self::assertFalse($this->lookup()->find(self::ORDER)->ok);

        $this->list([self::item(self::ID_A, $number, '711-2550636'), self::item(self::ID_B, $number, '711-2550637')]);
        $this->list([]);
        $this->statusAnswer(2);
        $this->statusAnswer(3);
        $gone = $this->lookup()->choose(self::ORDER, self::ID_B);
        self::assertFalse($gone->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);

        $this->list([self::item(self::ID_A, $number, '711-2550636'), self::item(self::ID_B, $number, '711-2550637')]);
        $this->list([]);
        $this->statusAnswer(2);
        $this->statusAnswer(2);
        $chosen = $this->lookup()->choose(self::ORDER, self::ID_B);
        self::assertTrue($chosen->ok);
        self::assertSame(self::ID_B, $this->rows->forOrder(self::ORDER)->parcel_id);
    }

    public function testChooseUnknownIdBindsNothing(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);
        $this->statusAnswer(2);

        $outcome = $this->lookup()->choose(self::ORDER, self::ID_B);

        self::assertFalse($outcome->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testBarcodeComparisonIgnoresDash(): void
    {
        $number = $this->uncertain();
        $this->rows->attach(2077, ['barcode' => '7112550636', 'parcel_id' => self::ID_B]);
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);

        self::assertFalse($this->lookup()->find(self::ORDER)->ok);
    }

    public function testCreatedRowIsNotSearched(): void
    {
        $this->rows->attach(self::ORDER, ['barcode' => '711-2550636', 'parcel_id' => self::ID_A]);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->calls());
    }

    public function testRunningReservationIsNotSearched(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->now - 5));

        self::assertFalse($this->lookup()->find(self::ORDER)->ok);
        self::assertSame([], $this->calls());
    }

    public function testApiFailureKeepsRow(): void
    {
        $this->uncertain();
        $this->transport->json(500, ['status' => 'Error']);

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testCorruptStatusAnswerKeepsRow(): void
    {
        $number = $this->uncertain();
        $this->list([self::item(self::ID_A, $number, '711-2550636')]);
        $this->list([]);
        $this->transport->json(500, ['status' => 'Error']);

        self::assertFalse($this->lookup()->find(self::ORDER)->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }
}
