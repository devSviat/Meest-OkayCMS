<?php

namespace Modules\Sviat\Meest\Templates;

use Modules\Sviat\Meest\Support\TemplateSmarty;
use Okay\Core\BackendTranslations;
use PHPUnit\Framework\TestCase;

/** Шаблони списку замовлень компілюються Smarty 4 і 5, екранують текст Meest і не лишають ключів без перекладу. */
class OrdersListTemplatesTest extends TestCase
{
    private const string XSS = '<img src=x onerror=alert(1)>';

    public function testBadgeEscapesMeestText(): void
    {
        $html = self::render('orders_status.tpl', ['order' => (object)['id' => 11, 'meest_list_parcel' => [
            'barcode' => '711-2550636', 'group' => 'arrived', 'statusKey' => 'sviat__meest__parcel_group_arrived', 'eventName' => self::XSS,
        ]]]);

        self::assertStringContainsString('data-copy-string="711-2550636"', $html);
        self::assertMatchesRegularExpression(
            '~<svg class="meest-list-status__logo" width="9.59" height="14" viewBox="126 65 261 381" aria-hidden="true">.*</svg>\s*<a [^>]*meest-list-status__barcode~s',
            $html,
            'glyph before the number',
        );
        self::assertStringContainsString('fill="#0056A4"', $html);
        self::assertStringContainsString('fill="#E40137"', $html);
        self::assertStringNotContainsString('<img', $html, 'inline, no request');
        self::assertStringContainsString('meest-parcel__badge--arrived', $html);
        self::assertStringContainsString('У відділенні', $html);
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertSame('', trim(self::render('orders_status.tpl', ['order' => (object)['id' => 12]])));
        $unsettled = self::render('orders_status.tpl', ['order' => (object)['id' => 13, 'meest_list_parcel' => ['barcode' => null, 'group' => 'unsettled', 'statusKey' => 'sviat__meest__list_unsettled', 'eventName' => null]]]);
        self::assertStringContainsString('meest-list-status__logo', $unsettled, 'the carrier is visible before the barcode exists');
        self::assertStringNotContainsString('sviat__meest__', $unsettled);
        self::assertStringNotContainsString('meest-list-status__barcode', $unsettled);
    }

    public function testMassResultEscapesRowsAndError(): void
    {
        $html = self::render('mass_result.tpl', ['meest_mass_result' => [
            'title' => 'створено 0 з 1',
            'rows' => [['orderId' => 7, 'kind' => 'failed', 'text' => self::XSS]],
            'untouched' => null,
            'error' => self::XSS,
        ]]);

        self::assertStringContainsString('meest-mass__row--failed', $html);
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertSame(2, substr_count($html, '&lt;img src=x'));
        self::assertStringNotContainsString('sviat__meest__', $html);
        self::assertSame('', trim(self::render('mass_result.tpl', ['meest_mass_result' => null])));
    }

    public function testActionsAreTranslated(): void
    {
        $actions = self::render('orders_action_option.tpl', []);

        self::assertStringContainsString('value="sviat_meest_create_parcels"', $actions);
        self::assertStringContainsString('value="sviat_meest_print_stickers"', $actions);
        self::assertStringNotContainsString('sviat__meest__', $actions, 'every translation key exists');
        self::assertStringNotContainsString('title=', $actions, 'bootstrap-select renders option titles as HTML');
        self::assertStringNotContainsString('data-', $actions);
        self::assertNotSame('', trim(strip_tags($actions)));
    }

    private static function render(string $template, array $vars): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/../Support/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/Meest/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-meest-list-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        $lang = [];
        include $root . '/Okay/Modules/Sviat/Meest/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SERVER['REQUEST_URI'] ??= '/backend/index.php?controller=OrdersAdmin';
        foreach ($vars as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    /** Номер копіюється так само, як у Нової Пошти: елемент ядра .fn_clipboard, підказка «Натисніть, щоб скопіювати», жовте «Скопійовано». */
    public function testNumberCopiesLikeNovaPoshta(): void
    {
        $html = self::render('orders_status.tpl', ['order' => (object)['id' => 11, 'meest_list_parcel' => ['barcode' => '711-2550636', 'group' => 'arrived', 'statusKey' => 'sviat__meest__parcel_group_arrived', 'eventName' => null]]]);
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Backend';
        $script = (string)file_get_contents($root . '/design/js/parcel.js');

        self::assertMatchesRegularExpression('~<a href="" class="meest-list-status__barcode fn_clipboard fn_meest_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="Натисніть, щоб скопіювати" data-hint-copied="Скопійовано" data-copy-string="711-2550636"~u', $html);
        self::assertStringContainsString("event.target.closest('.fn_meest_copy')", $script);
        self::assertStringNotContainsString('fn_meest_parcel_copy', $script);
        self::assertStringNotContainsString('fn_meest_list_copy', $script);
        self::assertStringNotContainsString('sviat__meest__list_copy', (string)file_get_contents($root . '/lang/ua.php'));
    }
}
