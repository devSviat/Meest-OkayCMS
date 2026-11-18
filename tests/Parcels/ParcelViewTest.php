<?php

namespace Modules\Sviat\Meest\Parcels;

use Modules\Sviat\Meest\Support\TemplateSmarty;
use Okay\Core\BackendTranslations;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use Okay\Modules\Sviat\Meest\Parcels\ParcelView;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

class ParcelViewTest extends TestCase
{
    private const int NOW = 1_900_000_000;
    private const string XSS = '<img src=x onerror=alert(1)>';
    private const string BRANCH = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string PARCEL_ID = 'ffffffff-0000-4000-8000-000000000001';

    private static function row(array $override = []): object
    {
        return (object)($override + [
            'order_id' => 7, 'parcel_id' => self::PARCEL_ID, 'barcode' => '711-2550636', 'parcel_number' => '7-3', 'contract_id' => 'dddddddd-0000-4000-8000-00000000000b',
            'state' => PendingParcel::CREATED, 'status_code' => null, 'status_group' => 'created', 'event_name' => null, 'event_at' => null,
            'delivery_price' => '85.00', 'declared_value' => '1200.00', 'receiver_pay' => 1, 'pay_type' => 'cash',
            'details' => null, 'history' => null, 'created_at' => '2030-03-17 10:00:00', 'reserved_at' => null, 'checked_at' => null,
        ]);
    }

    private static function details(array $override = []): string
    {
        return (string)json_encode($override + [
            'receiver_name' => 'Шевченко Тарас', 'receiver_phone' => '+380671234567', 'branch_id' => self::BRANCH, 'description' => 'Одяг',
            'notation' => 'Замовлення 7', 'delivery_date' => '2030-03-20', 'weight_kg' => 1.25, 'length' => 30, 'width' => 20, 'height' => 10,
            'volume' => 0.006, 'declared' => 1200.0,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function history(array $events): string
    {
        return (string)json_encode($events, JSON_UNESCAPED_UNICODE);
    }

    private static function tracked(array $override = []): object
    {
        return self::row($override + [
            'status_code' => 1213, 'status_group' => 'arrived', 'event_name' => 'Надійшло у відділення', 'event_at' => '2030-03-19 08:05:00',
            'details' => self::details(), 'checked_at' => '2030-03-19 09:00:00',
            'history' => self::history([
                ['code' => 2, 'name' => 'Створено', 'place' => 'Львів', 'at' => '2030-03-17 10:00:00'],
                ['code' => 1213, 'name' => 'Надійшло у відділення', 'place' => 'Київ', 'at' => '2030-03-19 08:05:00'],
            ]),
        ]);
    }

    private static function snapshot(string $branchId = self::BRANCH): object
    {
        return (object)['order_id' => 7, 'branch_id' => $branchId, 'branch_title' => 'Відділення №12', 'branch_address' => 'вул. Лісова, 1', 'city_name' => 'Київ'];
    }

    private static function draft(string $name = 'Шевченко Тарас', string $phone = '+380671234567'): ParcelDraft
    {
        $branch = (object)[
            'id' => self::BRANCH, 'name_ua' => 'Відділення №12', 'address_ua' => 'вул. Лісова, 1', 'weight_place_max' => '30.00',
            'insurance_max' => '50000.00', 'length_max' => 120, 'width_max' => 60, 'height_max' => 60, 'sending_only' => 0,
        ];

        return new ParcelDraft($name, $phone, $branch, 1.25, 30, 20, 10, 1200.0, true, 'cash', 'Одяг', 'Замовлення 7');
    }

    private static function build(?object $row, ParcelDraft|string|null $draft = null, bool $own = true, array $auth = [], ?object $snapshot = null): ?array
    {
        return ParcelView::build(7, $row, $own, static fn() => $draft ?? self::draft(), $auth, self::NOW, $snapshot);
    }

    public function testUncertainOffersLookupAndAttachOnly(): void
    {
        $uncertain = self::build(self::row(['state' => PendingParcel::UNCERTAIN, 'parcel_id' => null, 'barcode' => null]));
        $stale = self::build(self::row(['state' => PendingParcel::PENDING, 'parcel_id' => null, 'barcode' => null,
            'reserved_at' => date('Y-m-d H:i:s', self::NOW - PendingParcel::STALE_SECONDS - 1)]));

        foreach ([$uncertain, $stale] as $view) {
            self::assertSame('check', $view['mode']);
            self::assertSame(['lookup', 'attach', 'unlink'], $view['actions']);
            self::assertNotContains('create', $view['actions']);
            self::assertNull($view['card']);
            self::assertNull($view['form']);
        }
        $html = self::render('parcel_side.tpl', $uncertain);
        self::assertStringContainsString('fn_meest_parcel_lookup', $html);
        self::assertStringContainsString('fn_meest_parcel_attach', $html);
        self::assertStringNotContainsString('fn_meest_parcel_create', $html);

        $running = self::build(self::row(['state' => PendingParcel::PENDING, 'parcel_id' => null, 'barcode' => null,
            'reserved_at' => date('Y-m-d H:i:s', self::NOW - 5)]));
        self::assertSame('pending', $running['mode']);
        self::assertSame([], $running['actions'], 'nothing to press while creation is running');
    }

    public function testDeleteButtonOnlyWhenDeletable(): void
    {
        self::assertSame(['sticker', 'refresh', 'remove', 'unlink'], self::build(self::row())['actions']);
        self::assertSame(['sticker', 'refresh', 'remove', 'unlink'], self::build(self::row(['status_code' => 2]))['actions']);
        self::assertSame(['sticker', 'refresh', 'unlink'], self::build(self::row(['status_code' => 1213, 'status_group' => 'arrived']))['actions']);
        self::assertSame(['sticker', 'refresh', 'unlink'], self::build(self::row(['status_code' => 310, 'status_group' => 'created']))['actions'], 'created group, but not status 2');
        self::assertSame(['sticker', 'refresh', 'unlink'], self::build(self::row(['parcel_id' => null]))['actions'], 'attached by barcode: Meest deletes only by parcelID');

        self::assertStringContainsString('fn_meest_parcel_remove', self::render('parcel_main.tpl', self::build(self::row())));
        self::assertStringNotContainsString('fn_meest_parcel_remove', self::render('parcel_main.tpl', self::build(self::tracked())));
    }

    public function testAuthBannerOnOrderCard(): void
    {
        $auth = [['api' => 'token', 'reason' => 'bad_token']];

        foreach ([self::render('parcel_main.tpl', self::build(self::row(), auth: $auth)), self::render('parcel_side.tpl', self::build(null, auth: $auth))] as $html) {
            self::assertStringContainsString('Meest не приймає token', $html);
            self::assertStringContainsString('Відправлення не створюються, не друкуються й не видаляються.', $html);
        }
        self::assertStringNotContainsString('Meest не приймає token', self::render('parcel_main.tpl', self::build(self::row())));
    }

    public function testCardShowsSnapshotAndHistory(): void
    {
        $card = self::build(self::tracked(), snapshot: self::snapshot())['card'];

        self::assertSame('711-2550636', $card['barcode']);
        self::assertSame('https://t.meest-group.com/711-2550636', $card['trackUrl']);
        self::assertSame('arrived', $card['statusGroup']);
        self::assertSame('sviat__meest__parcel_group_arrived', $card['statusKey']);
        self::assertSame(['name' => 'Надійшло у відділення', 'place' => 'Київ', 'at' => '19.03.2030 08:05'], $card['event']);
        self::assertSame('Шевченко Тарас', $card['recipient']);
        self::assertSame('+380 67 123 45 67', $card['phone']);
        self::assertSame('Відділення №12, вул. Лісова, 1', $card['point']);
        self::assertSame('85.00', $card['deliveryPrice']);
        self::assertSame('sviat__meest__parcel_pays_receiver', $card['payerKey']);
        self::assertSame('sviat__meest__parcel_cash', $card['payTypeKey']);
        self::assertSame('20.03.2030', $card['plannedDate']);
        self::assertSame('1200.00', $card['declared']);
        self::assertSame(['weight' => '1.25', 'sides' => '30×20×10', 'volume' => '0.006', 'description' => 'Одяг', 'notation' => 'Замовлення 7', 'parcelNumber' => '7-3'], $card['more']);
        self::assertSame(['Надійшло у відділення', 'Створено'], array_column($card['history'], 'name'), 'newest first');
        self::assertSame('17.03.2030 10:00', $card['history'][1]['at']);
        self::assertSame('17.03.2030 10:00', $card['created']);
        self::assertSame('19.03.2030 09:00', $card['checked']);

        $html = self::render('parcel_main.tpl', self::build(self::tracked(), snapshot: self::snapshot()));
        self::assertStringContainsString('Відділення №12, вул. Лісова, 1', $html);
        self::assertStringContainsString('Історія подій', $html);
        self::assertStringContainsString('Показати більше', $html);
    }

    /** Пункт зі знімка замовлення лише тоді, коли посилка йде саме туди. */
    public function testPointOnlyWhenTheOrdersPointIsTheParcels(): void
    {
        self::assertNull(self::build(self::tracked(), snapshot: self::snapshot('bbbbbbbb-0000-4000-8000-000000000002'))['card']['point']);
        self::assertNull(self::build(self::tracked(['details' => self::details(['branch_id' => null])]), snapshot: self::snapshot())['card']['point'], 'attached: unknown point');
        self::assertSame('Відділення №12, вул. Лісова, 1', self::build(self::tracked(['details' => self::details(['branch_id' => strtoupper(self::BRANCH)])]), snapshot: self::snapshot())['card']['point']);
    }

    /** Порожнє поле картка не показує зовсім: ні «—», ні підпису. */
    public function testNoEmptyFields(): void
    {
        $bare = self::row(['delivery_price' => null, 'declared_value' => null, 'receiver_pay' => null, 'pay_type' => null]);
        $card = self::build($bare)['card'];

        foreach (['event', 'recipient', 'phone', 'point', 'deliveryPrice', 'payerKey', 'payTypeKey', 'plannedDate', 'declared', 'checked'] as $key) {
            self::assertNull($card[$key], $key);
        }
        self::assertSame(['parcelNumber' => '7-3'], $card['more']);
        self::assertSame([], $card['history']);

        $html = self::render('parcel_main.tpl', self::build($bare));
        self::assertStringNotContainsString('—', $html);
        foreach (['Одержувач', 'Пункт видачі', 'Доставка', 'Форма оплати', 'Планова доставка', 'Оголошена цінність', 'Історія подій', 'Статус перевірено'] as $label) {
            self::assertStringNotContainsString($label, $html, $label);
        }
        self::assertStringContainsString('Статус ще не перевіряли', $html);
        self::assertStringContainsString('Подій ще немає', self::render('parcel_main.tpl', self::build(self::row(['checked_at' => '2030-03-18 10:00:00']))));
    }

    public function testCorruptJsonCard(): void
    {
        $card = self::build(self::tracked(['details' => '{"receiver_name": ', 'history' => '[{"code": "x"}]']), snapshot: self::snapshot())['card'];

        self::assertSame('Надійшло у відділення', $card['event']['name']);
        self::assertNull($card['event']['place']);
        self::assertNull($card['recipient']);
        self::assertNull($card['point']);
        self::assertNull($card['plannedDate']);
        self::assertSame('1200.00', $card['declared'], 'the row column still has it');
        self::assertSame([], $card['history']);
        self::assertSame(['parcelNumber' => '7-3'], $card['more']);
    }

    public function testPlannedDateGoneWhenFinal(): void
    {
        foreach (['received' => 1622, 'returned' => 1825, 'cancelled' => 3] as $group => $code) {
            self::assertNull(self::build(self::tracked(['status_group' => $group, 'status_code' => $code]))['card']['plannedDate'], $group);
        }
        self::assertSame('20.03.2030', self::build(self::tracked(['status_group' => 'in_transit', 'status_code' => 101]))['card']['plannedDate']);
    }

    public function testTrackingLinkEncoded(): void
    {
        self::assertSame('https://t.meest-group.com/7112550636', self::build(self::row(['barcode' => ' 711 2550636 ']))['card']['trackUrl']);
        self::assertNull(self::build(self::row(['barcode' => '711/../x?y']))['card']['trackUrl'], 'not a barcode: no link');
        self::assertNull(self::build(self::row(['barcode' => null]))['card']['trackUrl']);

        $html = self::render('parcel_main.tpl', self::build(self::row()));
        self::assertStringContainsString('href="https://t.meest-group.com/711-2550636" target="_blank" rel="noopener noreferrer"', $html);
    }

    /** Знак Meest з preview.svg модуля, а не монограма й не зображення. */
    public function testHeaderUsesTheMeestGlyph(): void
    {
        $html = self::render('parcel_main.tpl', self::build(self::row()));

        self::assertMatchesRegularExpression('~<svg class="meest-card__logo" width="16" height="16" viewBox="40 39 433 433" aria-hidden="true">~', $html);
        self::assertStringContainsString('fill="#0056A4"', $html);
        self::assertStringContainsString('fill="#E40137"', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    /** Уся картка в рамці: шапка, тіло, смуга «Показати більше» й футер із діями — у цьому порядку. */
    public function testCardIsOneFrameWithHeaderBodyMoreAndFooter(): void
    {
        $html = self::render('parcel_main.tpl', self::build(self::tracked(), snapshot: self::snapshot()));
        $frame = substr($html, (int)strpos($html, '<div class="meest-card">'));
        $positions = array_map(static fn(string $part): int|false => strpos($frame, $part), [
            'class="meest-card__top"', 'class="meest-card__body"', 'class="meest-card__more" hidden', 'class="meest-card__footer"',
            'fn_meest_parcel_more', 'fn_meest_parcel_refresh', 'fn_meest_parcel_sticker', 'fn_meest_parcel_unlink',
        ]);

        self::assertNotContains(false, $positions);
        self::assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3], 'top, body, more, footer');
        foreach ([4, 5, 6, 7] as $i) {
            self::assertGreaterThan($positions[3], $positions[$i], 'toggle and actions live in the footer');
        }
        self::assertStringContainsString('Створено 17.03.2030 10:00', strip_tags($frame));
        self::assertSame('', trim(self::render('parcel_side.tpl', self::build(self::row()))), 'the card lives under the products');
    }

    public function testFormWithoutParcel(): void
    {
        $view = self::build(null);

        self::assertSame('form', $view['mode']);
        self::assertSame(['create', 'attach'], $view['actions']);
        self::assertSame('1.25', $view['form']['weight']);
        self::assertSame('1200.00', $view['form']['declared']);
        self::assertSame('receiver', $view['form']['payer']);
        self::assertSame('Відділення №12, вул. Лісова, 1', $view['form']['point']);

        $html = self::render('parcel_side.tpl', $view);
        foreach (['receiver_name', 'receiver_phone', 'weight', 'length', 'width', 'height', 'declared_value', 'payer', 'pay_type', 'description', 'notation'] as $field) {
            self::assertStringContainsString('data-field="' . $field . '"', $html, $field);
        }
        self::assertStringNotContainsString('name="weight"', $html, 'fields stay out of the order form');
        self::assertStringNotContainsString('<option title=', $html, 'bootstrap-select would insert the title as HTML');
    }

    public function testDraftProblemsAreShownBeforeCreating(): void
    {
        $view = self::build(null, self::draft(name: 'Тарас', phone: '+380441234567'));

        self::assertSame('sviat__meest__parcel_err_name', $view['issues']['receiver_name']);
        self::assertSame('sviat__meest__parcel_err_phone', $view['issues']['receiver_phone']);
        self::assertStringContainsString('щонайменше два слова', self::render('parcel_side.tpl', $view));
    }

    public function testOrderWithoutPointShowsRefusalAndAttach(): void
    {
        $view = self::build(null, 'sviat__meest__parcel_err_no_point');

        self::assertSame('refusal', $view['mode']);
        self::assertSame('sviat__meest__parcel_err_no_point', $view['refusalKey']);
        self::assertSame(['attach'], $view['actions']);
    }

    public function testForeignOrderWithoutParcelShowsNothingButKeepsItsParcel(): void
    {
        self::assertNull(self::build(null, own: false));
        self::assertSame('card', self::build(self::row(), own: false)['mode'], 'switched delivery: the parcel still has to be deleted');
    }

    /** Подія, місце, опис, notation, ПІБ і телефон — тексти Meest і менеджера: адмінка без autoescape. */
    public function testEveryModeRendersEscaped(): void
    {
        $card = self::render('parcel_main.tpl', self::build(self::tracked([
            'event_name' => self::XSS, 'barcode' => self::XSS, 'parcel_number' => self::XSS,
            'details' => self::details(['description' => self::XSS, 'notation' => self::XSS, 'receiver_name' => self::XSS, 'receiver_phone' => self::XSS]),
            'history' => self::history([['code' => 1213, 'name' => self::XSS, 'place' => self::XSS, 'at' => '2030-03-19 08:05:00']]),
        ]), snapshot: (object)['branch_id' => self::BRANCH, 'branch_title' => self::XSS, 'branch_address' => '']));
        self::assertStringNotContainsString(self::XSS, $card);
        // Подія, місце (у рядку події й в історії), назва в історії, опис, notation, ПІБ, телефон, пункт.
        self::assertGreaterThanOrEqual(9, substr_count($card, '&lt;img src=x'));

        $form = self::render('parcel_side.tpl', self::build(null, self::draft(name: self::XSS, phone: self::XSS)));
        self::assertStringNotContainsString(self::XSS, $form);

        $check = self::render('parcel_side.tpl', self::build(self::row(['state' => PendingParcel::UNCERTAIN, 'parcel_id' => null, 'barcode' => null])));
        foreach ([$card, $form, $check, self::render('parcel_side.tpl', self::build(null, 'sviat__meest__parcel_err_no_point'))] as $html) {
            self::assertStringNotContainsString('sviat__meest__', $html, 'every translation key exists');
        }
    }

    /** Стікер відкривається в новій вкладці лише як blob PDF і без доступу вкладки до сторінки замовлення. */
    public function testStickerTabIsDetachedAndScriptInsertsNoMarkup(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Backend/design/js/parcel.js');

        self::assertMatchesRegularExpression("~var tab = window\\.open\\('', '_blank'\\);\\s*if \\(tab\\) \\{\\s*tab\\.opener = null;~", $js);
        self::assertStringContainsString("new Blob([bytes], { type: 'application/pdf' })", $js);
        self::assertStringContainsString('URL.revokeObjectURL(url); }, 60000)', $js);
        self::assertStringContainsString('Content-Disposition', $js, 'stock rewrites Content-Type of the PDF');
        self::assertStringContainsString('response.redirected', $js);
        self::assertStringNotContainsString('innerHTML', $js);
        // toastr 2.1.4 вставляє текст як HTML без escapeHtml; кожен виклик іде через toast().
        self::assertSame(1, substr_count($js, 'window.toastr['));
        self::assertStringContainsString('{ escapeHtml: true }', $js);
        self::assertStringContainsString('CSS.escape(issue.field)', $js);
        self::assertStringContainsString('writeText(value).then(', $js, 'a refused clipboard is not reported as copied');
    }

    private static function render(string $template, ?array $view): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/../Support/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/Meest/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-meest-parcel-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        $lang = [];
        include $root . '/Okay/Modules/Sviat/Meest/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SESSION['id'] ??= 'test-session';
        $smarty->assign('meest_parcel', $view);

        return $smarty->fetch($template);
    }
}
