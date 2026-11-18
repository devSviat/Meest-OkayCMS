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
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use Okay\Modules\Sviat\Meest\Parcels\StickerIssue;
use Okay\Modules\Sviat\Meest\Parcels\Stickers;
use PHPUnit\Framework\TestCase;

class StickersTest extends TestCase
{
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';
    private const string OTHER_CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000b';
    private const string PARCEL_A = 'eeeeeeee-0000-4000-8000-0000000000a2';
    private const string PARCEL_B = 'eeeeeeee-0000-4000-8000-0000000000a1';

    private FakeTransport $http;
    private InMemoryParcelRows $rows;
    private ArrayLogger $logger;
    private Stickers $stickers;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->rows = new InMemoryParcelRows();
        $this->logger = new ArrayLogger();
        $state = new InMemoryState();
        $credentials = new FixedCredentials(new Credentials('cabinet-token-abc', self::CONTRACT));
        $now = static fn(): int => 1_800_000_000;
        $client = new MeestClient(
            $this->http,
            new TokenProvider($credentials, $state, new AuthFlags($state), $now),
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            $this->logger,
        );
        $this->stickers = new Stickers($client, $this->rows);
    }

    /** @param array<string, mixed> $values */
    private function row(int $orderId, array $values): void
    {
        self::assertTrue($this->rows->attach($orderId, $values));
    }

    private function pdf(string $marker): HttpResponse
    {
        return new HttpResponse(200, "%PDF-1.4 {$marker}", 'application/pdf');
    }

    public function testOneByParcelId(): void
    {
        $this->row(1, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-2550636', 'contract_id' => self::CONTRACT]);
        $this->http->push($this->pdf('one'));

        $file = $this->stickers->one(1);

        self::assertSame('application/pdf', $file->type);
        self::assertSame('%PDF-1.4 one', $file->bytes);
        self::assertStringEndsWith('print/sticker100/' . self::PARCEL_A, $this->http->requests()[0]->url);
    }

    public function testOneByBarcodeWithAgent(): void
    {
        $this->row(1, ['barcode' => '711-2550636', 'contract_id' => self::OTHER_CONTRACT]);
        $this->http->push($this->pdf('one'));

        $this->stickers->one(1);

        self::assertStringEndsWith('print/sticker100/711-2550636?agent=' . self::OTHER_CONTRACT, $this->http->requests()[0]->url);
    }

    public function testOneWithoutRowOrIdentityRefused(): void
    {
        $this->row(2, ['barcode' => '711-2550636']);

        foreach ([1, 2] as $orderId) {
            try {
                $this->stickers->one($orderId);
                self::fail('expected StickerIssue');
            } catch (StickerIssue $e) {
                self::assertSame('sviat__meest__sticker_err_none', $e->getMessage());
            }
        }
        self::assertSame([], $this->http->requests());
    }

    public function testManyJoinsIdsWithComma(): void
    {
        $this->row(1, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111', 'contract_id' => self::CONTRACT]);
        $this->row(2, ['parcel_id' => self::PARCEL_B, 'barcode' => '711-2222222', 'contract_id' => self::CONTRACT]);
        $this->http->push($this->pdf('both'));

        $result = $this->stickers->many([1, 2]);

        self::assertSame([], $result['skipped']);
        self::assertSame('application/pdf', $result['file']->type);
        self::assertCount(1, $this->http->requests());
        self::assertStringEndsWith('print/sticker100/' . self::PARCEL_A . ',' . self::PARCEL_B, $this->http->requests()[0]->url);
    }

    public function testMixedRowsGiveZipAndSkipUncertain(): void
    {
        $this->row(1, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111', 'contract_id' => self::CONTRACT]);
        $this->row(2, ['barcode' => '711-2222222', 'contract_id' => self::OTHER_CONTRACT]);
        $this->row(3, ['barcode' => '711-3333333', 'contract_id' => self::OTHER_CONTRACT]);
        $this->row(5, ['barcode' => '711-5555555']);
        $uncertain = $this->rows->reserve(4, '2026-11-17 10:00:00');
        self::assertNotNull($uncertain);
        $this->rows->markUncertain(4);
        $this->http->push($this->pdf('by-id'), $this->pdf('by-barcode'));

        $result = $this->stickers->many([1, 2, 3, 4, 5, 6]);

        self::assertSame('application/zip', $result['file']->type);
        $zip = new \ZipArchive();
        $path = tempnam(sys_get_temp_dir(), 'st');
        file_put_contents($path, $result['file']->bytes);
        self::assertTrue($zip->open($path));
        self::assertSame(2, $zip->numFiles);
        self::assertSame('%PDF-1.4 by-id', $zip->getFromIndex(0));
        self::assertSame('%PDF-1.4 by-barcode', $zip->getFromIndex(1));
        $zip->close();
        unlink($path);
        self::assertSame(
            [4 => 'sviat__meest__sticker_skip_pending', 5 => 'sviat__meest__sticker_skip_no_contract', 6 => 'sviat__meest__sticker_skip_no_parcel'],
            $result['skipped'],
        );
        $urls = array_map(static fn($r) => $r->url, $this->http->requests());
        self::assertStringEndsWith('print/sticker100/' . self::PARCEL_A, $urls[0]);
        self::assertStringEndsWith('print/sticker100/711-2222222,711-3333333?agent=' . self::OTHER_CONTRACT, $urls[1]);
    }

    /** @return iterable<string, array{HttpResponse}> */
    public static function badAnswers(): iterable
    {
        yield 'html 200' => [new HttpResponse(200, '<html>x</html>', 'text/html')];
        yield 'server error' => [new HttpResponse(500, '{}', 'application/json')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAnswers')]
    public function testRowContractNeverReachesLogOrMessage(HttpResponse $answer): void
    {
        $this->row(1, ['barcode' => '711-2550636', 'contract_id' => self::OTHER_CONTRACT]);
        $this->http->push($answer);

        try {
            $this->stickers->one(1);
            self::fail('expected MeestApiException');
        } catch (\Okay\Modules\Sviat\Meest\Api\MeestApiException $e) {
            self::assertStringNotContainsString(self::OTHER_CONTRACT, $e->getMessage());
        }
        self::assertStringNotContainsString(self::OTHER_CONTRACT, $this->logger->text());
        self::assertStringContainsString('agent={contract}', $this->logger->text());
    }

    public function testManyDeduplicatesOrderIds(): void
    {
        $this->row(1, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111', 'contract_id' => self::CONTRACT]);
        $this->http->push($this->pdf('x'));

        $this->stickers->many([1, 1]);

        self::assertStringEndsWith('print/sticker100/' . self::PARCEL_A, $this->http->requests()[0]->url);
    }

    public function testNothingToPrintGivesNoFile(): void
    {
        $result = $this->stickers->many([7]);

        self::assertNull($result['file']);
        self::assertSame([7 => 'sviat__meest__sticker_skip_no_parcel'], $result['skipped']);
    }

    public function testMoreThanTwentyRefused(): void
    {
        $this->expectException(StickerIssue::class);
        $this->expectExceptionMessage('sviat__meest__sticker_err_too_many');

        $this->stickers->many(range(1, Stickers::MAX + 1));
    }
}
