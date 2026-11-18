<?php

namespace Modules\Sviat\Meest\Parcels;

require_once __DIR__ . '/../Support/MassWorld.php';

use Modules\Sviat\Meest\Support\MassWorld;
use Modules\Sviat\Meest\Support\MassWorldDraft;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Parcels\MassParcels;
use Okay\Modules\Sviat\Meest\Parcels\MassReport;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

class MassParcelsTest extends TestCase
{
    use MassWorld;

    private const string PARCEL_ID = 'ffffffff-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        $this->setUpWorld();
    }

    /** @return array<int, array{kind: string, key: ?string, value: ?string}> за id замовлення */
    private static function byOrder(MassReport $report): array
    {
        $rows = [];
        foreach ($report->rows as $row) {
            $rows[$row['orderId']] = ['kind' => $row['kind'], 'key' => $row['key'], 'value' => $row['value']];
        }

        return $rows;
    }

    public function testSkipsForeignAndAnyExistingRow(): void
    {
        $this->foreign = [501];
        $this->createdRow(502, ['parcel_id' => self::PARCEL_ID, 'barcode' => '711-2550636']);
        $this->rows->reserve(503, date('Y-m-d H:i:s', $this->now));
        $this->rows->reserve(504, date('Y-m-d H:i:s', $this->now));
        $this->rows->markUncertain(504);
        $this->drafts[505] = 'sviat__meest__parcel_err_no_point';
        $this->drafts[506] = MassWorldDraft::valid(0.0);
        $this->createdRow(507, ['barcode' => '7112222222', 'status_group' => 'received']);

        $report = $this->mass()->run(['501', '502', '503', '504', '505', '506', '507']);

        self::assertSame([
            501 => ['kind' => 'skipped', 'key' => 'sviat__meest__parcel_err_not_own', 'value' => null],
            502 => ['kind' => 'skipped', 'key' => 'sviat__meest__mass_has_parcel', 'value' => '711-2550636'],
            503 => ['kind' => 'skipped', 'key' => 'sviat__meest__mass_unsettled', 'value' => null],
            504 => ['kind' => 'skipped', 'key' => 'sviat__meest__mass_unsettled', 'value' => null],
            505 => ['kind' => 'skipped', 'key' => 'sviat__meest__parcel_err_no_point', 'value' => null],
            506 => ['kind' => 'skipped', 'key' => 'sviat__meest__parcel_err_weight', 'value' => null],
            507 => ['kind' => 'skipped', 'key' => 'sviat__meest__mass_has_parcel', 'value' => '7112222222'],
        ], self::byOrder($report));
        self::assertSame(0, $report->untouched);
        self::assertSame([], $this->transport->requests(), 'no order reached Meest');
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(504)->state, 'the unsettled row is left as it was');
    }

    public function testCreatesTheValidOne(): void
    {
        $this->foreign = [601];
        $this->createdAnswer(self::PARCEL_ID, '711-2550636');

        $report = $this->mass()->run([601, 602]);

        self::assertSame(['kind' => 'created', 'key' => null, 'value' => '711-2550636'], self::byOrder($report)[602]);
        self::assertSame(1, $report->created());
        self::assertSame(self::PARCEL_ID, $this->rows->forOrder(602)->parcel_id);
        self::assertCount(1, $this->transport->requests(), 'one POST parcel');
    }

    public function testMeestRefusalIsReportedWithItsText(): void
    {
        $this->transport->json(400, ['status' => 'Error', 'info' => ['fieldName' => 'receiver.phone', 'message' => 'Phone is invalid', 'messageDetails' => '']]);

        $row = self::byOrder($this->mass()->run([701]))[701];

        self::assertSame('failed', $row['kind']);
        self::assertSame('sviat__meest__parcel_api_rejected', $row['key']);
        self::assertSame('Phone is invalid', $row['value']);
        self::assertNull($this->rows->forOrder(701), 'nothing was created, the order stays free');
    }

    public function testUnexpectedFailureIsLoggedWithoutMessageAndTheRestGoesOn(): void
    {
        $this->transport->push(new \RuntimeException('socket closed for Тестенко +380671234567'));
        $this->createdAnswer(self::PARCEL_ID, '711-2550636');

        $rows = self::byOrder($this->mass()->run(['401', '402']));

        self::assertSame(['kind' => 'failed', 'key' => 'sviat__meest__mass_failed_generic', 'value' => null], $rows[401]);
        self::assertSame('created', $rows[402]['kind']);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(401)->state, 'unknown result is not retried');
        self::assertStringContainsString('RuntimeException', $this->logger->text());
        self::assertStringContainsString('401', $this->logger->text());
        self::assertStringNotContainsString('380671234567', $this->logger->text());
        self::assertStringNotContainsString('Тестенко', $this->logger->text());
    }

    /** Неправильний токен відмовить кожному замовленню однаково: решта пакета не йде до Meest і має ту саму причину в звіті. */
    public function testBadTokenStopsTheBatch(): void
    {
        $this->transport->json(401, ['status' => 'Error', 'info' => ['message' => 'Unauthorized']]);
        $this->createdAnswer(self::PARCEL_ID, '711-2550636');

        $report = $this->mass()->run([1001, 1002, 1003]);

        self::assertSame([
            1001 => ['kind' => 'failed', 'key' => 'sviat__meest__parcel_api_bad_token', 'value' => null],
            1002 => ['kind' => 'skipped', 'key' => 'sviat__meest__parcel_api_bad_token', 'value' => null],
            1003 => ['kind' => 'skipped', 'key' => 'sviat__meest__parcel_api_bad_token', 'value' => null],
        ], self::byOrder($report));
        self::assertCount(1, $this->transport->requests(), 'no request after the refusal');
        self::assertSame([], $this->rows->rows, 'no order was reserved after the refusal');
    }

    public function testMissingKeyStopsTheBatch(): void
    {
        $this->credentials = new Credentials('', '');

        $report = $this->mass()->run([1101, 1102]);

        self::assertSame([
            1101 => ['kind' => 'failed', 'key' => 'sviat__meest__parcel_api_not_configured', 'value' => null],
            1102 => ['kind' => 'skipped', 'key' => 'sviat__meest__parcel_api_not_configured', 'value' => null],
        ], self::byOrder($report));
        self::assertSame([], $this->transport->requests());
        self::assertSame(1, $this->rows->reserveCalls, 'the rest is not even reserved');
    }

    public function testStopsAtBudget(): void
    {
        $this->foreign = [801, 802, 803, 804];
        // Початок, потім перевірка перед кожним замовленням: третє почалося б уже після бюджету.
        $this->ticks = [0.0, 0.0, 10.0, MassParcels::BUDGET_SECONDS + 1];

        $report = $this->mass()->run([801, 802, 803, 804]);

        self::assertSame([801, 802], array_column($report->rows, 'orderId'));
        self::assertSame(2, $report->untouched);
    }

    public function testLimitAndIdsFromPost(): void
    {
        $raw = ['0', '-3', 'abc', '1e3', ['x'], '12', 12, ...array_map(strval(...), range(901, 925))];
        $this->foreign = range(1, 1000);

        $report = $this->mass()->run($raw);

        self::assertSame(20, MassParcels::LIMIT);
        self::assertSame([12, ...range(901, 919)], array_column($report->rows, 'orderId'));
        self::assertSame(6, $report->untouched);
    }
}
