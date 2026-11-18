<?php

namespace Modules\Sviat\Meest\Orders;

require_once __DIR__ . '/../Support/MassWorld.php';

use Modules\Sviat\Meest\Support\MassWorld;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Meest\Parcels\MassReport;
use Okay\Modules\Sviat\Meest\Parcels\StickerFile;
use PHPUnit\Framework\TestCase;

class OrdersMassActionTest extends TestCase
{
    use MassWorld;

    private const string PARCEL_A = 'eeeeeeee-0000-4000-8000-0000000000a2';
    private const string PARCEL_B = 'eeeeeeee-0000-4000-8000-0000000000a1';

    protected function setUp(): void
    {
        $this->setUpWorld();
    }

    private function action(): OrdersMassAction
    {
        return new OrdersMassAction($this->mass(), $this->stickers(), $this->logger);
    }

    private static function post(string $action, array $ids = ['301', '302']): array
    {
        return ['action' => $action, 'check' => $ids];
    }

    private function pdf(string $marker): HttpResponse
    {
        return new HttpResponse(200, "%PDF-1.4 {$marker}", 'application/pdf');
    }

    public function testIgnoredWithoutSession(): void
    {
        $this->createdRow(301, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111']);
        foreach ([OrdersMassAction::CREATE, OrdersMassAction::PRINT] as $action) {
            $session = [];

            self::assertFalse($this->action()->handle(self::post($action), false, $session));
            self::assertSame([], $session);
        }
        self::assertSame([], $this->transport->requests());
        self::assertNull($this->rows->forOrder(302));
    }

    public function testOtherActionsAndMissingIdsAreIgnored(): void
    {
        $session = [];

        self::assertFalse($this->action()->handle(self::post('delete'), true, $session));
        self::assertFalse($this->action()->handle(self::post('sviat_up_create_shipments'), true, $session));
        self::assertFalse($this->action()->handle(['action' => OrdersMassAction::CREATE, 'check' => '301'], true, $session));
        self::assertFalse($this->action()->handle(['action' => OrdersMassAction::CREATE], true, $session));
        self::assertSame([], $session);
        self::assertSame([], $this->transport->requests());
    }

    public function testStockSessionCheckThatDropsPostDoesNotCrash(): void
    {
        $saved = $_POST;
        $_POST = self::post(OrdersMassAction::CREATE);
        $session = [];
        try {
            $handled = $this->action()->handleRequest(static function (): bool {
                // Request::checkSession() стоку 4.5.3 на хибному токені.
                unset($_POST);

                return false;
            }, $session);
        } finally {
            $_POST = $saved;
        }

        self::assertFalse($handled);
        self::assertSame([], $session);
    }

    public function testLimitTwenty(): void
    {
        $this->foreign = range(1, 100);
        $session = ['other' => 1];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::CREATE, array_map(strval(...), range(1, 25))), true, $session));
        $taken = OrdersMassAction::take($session);

        self::assertInstanceOf(MassReport::class, $taken['report']);
        self::assertSame(range(1, 20), array_column($taken['report']->rows, 'orderId'));
        self::assertSame(5, $taken['report']->untouched);
        self::assertSame(['other' => 1], $session, 'taken once');
        self::assertNull(OrdersMassAction::take($session));
        self::assertSame([], $this->transport->requests());
    }

    public function testPrintStreamsOnePdf(): void
    {
        $this->createdRow(301, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111']);
        $this->createdRow(302, ['parcel_id' => self::PARCEL_B, 'barcode' => '711-2222222']);
        $this->transport->push($this->pdf('ab'));
        $session = [];

        $file = $this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session);

        self::assertInstanceOf(StickerFile::class, $file);
        self::assertSame('application/pdf', $file->type);
        self::assertSame('%PDF-1.4 ab', $file->bytes);
        self::assertSame([], $session, 'nothing skipped, nothing to report');
        self::assertStringEndsWith('print/sticker100/' . self::PARCEL_A . ',' . self::PARCEL_B, $this->transport->requests()[0]->url);
    }

    /** Файл іде браузеру, а пропущені замовлення чекають у сесії на наступне відкриття списку. */
    public function testPrintKeepsSkippedForTheList(): void
    {
        $this->createdRow(301, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111']);
        $this->rows->reserve(302, date('Y-m-d H:i:s', $this->now));
        $this->rows->markUncertain(302);
        $this->transport->push($this->pdf('a'));
        $session = [];

        self::assertInstanceOf(StickerFile::class, $this->action()->handle(self::post(OrdersMassAction::PRINT, ['301', '302', '303']), true, $session));

        $taken = OrdersMassAction::take($session);
        self::assertSame(OrdersMassAction::PRINT, $taken['action']);
        self::assertSame([
            ['orderId' => 302, 'kind' => 'skipped', 'key' => 'sviat__meest__sticker_skip_pending', 'value' => null],
            ['orderId' => 303, 'kind' => 'skipped', 'key' => 'sviat__meest__sticker_skip_no_parcel', 'value' => null],
        ], $taken['report']->rows);
        self::assertSame(3, $taken['printOf']);
    }

    public function testPrintWithNothingPrintableReportsInsteadOfStreaming(): void
    {
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        $taken = OrdersMassAction::take($session);
        self::assertSame(['key' => 'sviat__meest__sticker_err_none', 'details' => null], $taken['error']);
        self::assertCount(2, $taken['report']->rows);
        self::assertSame([], $this->transport->requests());
    }

    public function testPrintOverTwentyIsRefused(): void
    {
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT, array_map(strval(...), range(1, 21))), true, $session));

        self::assertSame('sviat__meest__sticker_err_too_many', OrdersMassAction::take($session)['error']['key']);
        self::assertSame([], $this->transport->requests());
    }

    /** Відмова Meest на одному з договорів зриває весь друк: менеджер бачить причину, а не півфайла. */
    public function testMeestRefusalFailsTheWholePrint(): void
    {
        $this->createdRow(301, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111']);
        $this->createdRow(302, ['barcode' => '711-2222222', 'contract_id' => 'dddddddd-0000-4000-8000-00000000000a']);
        $this->transport->push($this->pdf('a'))->json(403, ['status' => 'Error', 'info' => ['fieldName' => '', 'message' => 'Agent is not allowed', 'messageDetails' => '']]);
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        $error = OrdersMassAction::take($session)['error'];
        self::assertStringStartsWith('sviat__meest__', $error['key']);
    }

    public function testNotPdfAnswerHasItsOwnText(): void
    {
        $this->createdRow(301, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111']);
        $this->transport->push(new HttpResponse(200, '<html>login</html>', 'text/html'));
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        self::assertSame(['key' => 'sviat__meest__sticker_not_pdf', 'details' => null], OrdersMassAction::take($session)['error']);
    }

    /** Збій поза Meest (БД, zip) не дає 500: менеджер повертається до списку з повідомленням. */
    public function testUnexpectedPrintFailureIsReportedAndLoggedWithoutMessage(): void
    {
        $this->createdRow(301, ['parcel_id' => self::PARCEL_A, 'barcode' => '711-1111111']);
        $this->transport->push(new \RuntimeException('socket closed for cabinet-token-abc'));
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        self::assertSame(['key' => 'sviat__meest__mass_print_generic', 'details' => null], OrdersMassAction::take($session)['error']);
        self::assertStringContainsString('RuntimeException', $this->logger->text());
        self::assertStringNotContainsString('cabinet-token-abc', $this->logger->text());
    }

    public function testViewTranslatesRowsAndError(): void
    {
        $texts = [
            'sviat__meest__mass_title' => 'створено %1$s з %2$s',
            'sviat__meest__mass_print_title' => 'надруковано %1$s з %2$s',
            'sviat__meest__mass_created' => 'посилка %s',
            'sviat__meest__mass_skipped' => 'пропущено',
            'sviat__meest__mass_failed' => 'не створено',
            'sviat__meest__mass_has_parcel' => 'посилка вже є:',
            'sviat__meest__parcel_err_phone' => 'Перевірте телефон.',
            'sviat__meest__parcel_api_rejected' => 'Meest відхилила запит.',
            'sviat__meest__mass_untouched' => 'до %1$s; решту %2$s не чіпали',
            'sviat__meest__sticker_err_none' => 'Стікерів немає.',
            'sviat__meest__sticker_skip_pending' => 'посилка ще не створена',
        ];
        $text = static fn(string $key): string => $texts[$key];
        $report = new MassReport([
            ['orderId' => 1, 'kind' => 'created', 'key' => null, 'value' => '711-2550636'],
            ['orderId' => 2, 'kind' => 'skipped', 'key' => 'sviat__meest__mass_has_parcel', 'value' => '711-1111111'],
            ['orderId' => 3, 'kind' => 'failed', 'key' => 'sviat__meest__parcel_api_rejected', 'value' => 'Phone is invalid'],
            ['orderId' => 4, 'kind' => 'skipped', 'key' => 'sviat__meest__parcel_err_phone', 'value' => null],
        ], 4);

        $view = OrdersMassAction::view(['action' => OrdersMassAction::CREATE, 'report' => $report, 'error' => null, 'printOf' => null], $text);

        self::assertSame('створено 1 з 4', $view['title']);
        self::assertSame([
            ['orderId' => 1, 'kind' => 'created', 'text' => 'посилка 711-2550636'],
            ['orderId' => 2, 'kind' => 'skipped', 'text' => 'пропущено: посилка вже є: 711-1111111'],
            ['orderId' => 3, 'kind' => 'failed', 'text' => 'не створено: Meest відхилила запит. (Phone is invalid)'],
            ['orderId' => 4, 'kind' => 'skipped', 'text' => 'пропущено: Перевірте телефон.'],
        ], $view['rows']);
        self::assertSame('до 20; решту 4 не чіпали', $view['untouched']);
        self::assertNull($view['error']);

        $print = OrdersMassAction::view([
            'action' => OrdersMassAction::PRINT,
            'report' => new MassReport([['orderId' => 9, 'kind' => 'skipped', 'key' => 'sviat__meest__sticker_skip_pending', 'value' => null]], 0),
            'error' => ['key' => 'sviat__meest__sticker_err_none', 'details' => null],
            'printOf' => 1,
        ], $text);
        self::assertSame('надруковано 0 з 1', $print['title']);
        self::assertSame('Стікерів немає.', $print['error']);
        self::assertSame([['orderId' => 9, 'kind' => 'skipped', 'text' => 'пропущено: посилка ще не створена']], $print['rows']);
    }

    public function testForgedSessionIsDropped(): void
    {
        $forged = [
            ['report' => ['rows' => [['orderId' => '1<script>', 'kind' => 'created']], 'untouched' => 0]],
            ['error' => ['key' => 'orders_permanently_delete', 'details' => null]],
            ['report' => ['rows' => [['orderId' => 1, 'kind' => 'skipped', 'key' => 'other__key', 'value' => null]], 'untouched' => 0]],
        ];
        foreach ($forged as $data) {
            $session = ['sviat_meest_mass' => $data];

            self::assertNull(OrdersMassAction::take($session));
            self::assertSame([], $session);
        }
    }
}
