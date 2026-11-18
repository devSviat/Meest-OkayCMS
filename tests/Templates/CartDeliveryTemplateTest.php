<?php

namespace Modules\Sviat\Meest\Templates;

use Modules\Sviat\Meest\Support\TemplateSmarty;
use Okay\Modules\Sviat\Meest\Cart\ThemeClasses;
use PHPUnit\Framework\TestCase;

/** Блок кошика, відрендерений справжнім Smarty: форма надсилає саме ті поля, які читає валідатор. */
class CartDeliveryTemplateTest extends TestCase
{
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';

    private static function dir(): string
    {
        return dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/design/html';
    }

    private static function render(array $vars, string $template = 'cart_delivery.tpl'): string
    {
        require_once __DIR__ . '/../Support/TemplateSmarty.php';
        $smarty = TemplateSmarty::create([self::dir()], sys_get_temp_dir() . '/okaycms-meest-tpl-' . getmypid());
        $lang = [];
        include dirname(self::dir()) . '/lang/ua.php';
        $smarty->assign('lang', (object)$lang);
        foreach ($vars as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    private static function vars(array $override = []): array
    {
        return $override + [
            'delivery' => (object)['id' => 6, 'module_id' => 81, 'hide_front_delivery_price' => 0],
            'meest_module_id' => 81,
            'meest_unavailable' => false,
            'meest_theme' => ThemeClasses::for('okay_shop'),
            'meest_popular' => [['id' => self::CITY, 'label' => 'Вигадане', 'type' => 'м.']],
            'meest_texts_json' => '{"cart_busy":"\"<b>\""}',
            'meest_urls_json' => '{"quote":"https://shop.test/ajax/meest/quote?x=\"<i>"}',
            'meest_prefill' => [6 => [
                'city' => ['id' => self::CITY, 'name' => 'Вигадане <script>', 'type' => 'м.', 'district' => '', 'region' => 'Тестова'],
                'branch' => null,
                'city_json' => '{"id":"' . self::CITY . '","name":"Вигадане <script>"}',
                'branch_json' => '',
            ]],
        ];
    }

    public function testFormCarriesCityAndBranchIdsForThisDelivery(): void
    {
        $html = self::render(self::vars());

        self::assertMatchesRegularExpression('/<input type="hidden" class="meest-cart__city-id" name="meest_city_id\[6\]" value="' . self::CITY . '">/', $html);
        self::assertMatchesRegularExpression('/<input type="hidden" class="meest-cart__branch-id" name="meest_branch_id\[6\]" value="">/', $html);
        self::assertStringContainsString('data-urls="', $html);
    }

    public function testDirectoryDataIsEscaped(): void
    {
        $html = self::render(self::vars());

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Вигадане &lt;script&gt;', $html);
        self::assertStringNotContainsString('"<b>"', $html);
        self::assertStringNotContainsString('<i>', $html);
    }

    /** S7: дані йдуть у data-*, а не інлайн-скриптом; жоден шаблон модуля не містить <script>. */
    public function testNoInlineScriptWithData(): void
    {
        foreach (glob(self::dir() . '/*.tpl') ?: [] as $file) {
            self::assertStringNotContainsStringIgnoringCase('<script', (string)file_get_contents($file), basename($file));
        }
        self::assertStringNotContainsStringIgnoringCase('<script', self::render(self::vars()));
    }

    public function testOtherDeliveriesGetNothing(): void
    {
        self::assertSame('', trim(self::render(self::vars(['delivery' => (object)['id' => 6, 'module_id' => 12, 'hide_front_delivery_price' => 0]]))));
    }

    public function testUnavailableDictionaryShowsOnlyTheMessage(): void
    {
        $html = self::render(self::vars(['meest_unavailable' => true]));

        self::assertStringContainsString('meest-cart-unavailable', $html);
        self::assertStringNotContainsString('meest_branch_id', $html);
    }

    public function testOrderPageShowsDateFromSnapshot(): void
    {
        $html = self::render(['meest_order_family' => 'okay', 'meest_order_delivery' => ['city' => 'м. Вигадане', 'point' => 'Відділення №1 <b>', 'estimatedDate' => '2026-11-20', 'priceUnconfirmed' => false]], 'order_delivery.tpl');

        self::assertStringContainsString('20.11.2026', $html);
        self::assertStringContainsString('Відділення №1 &lt;b&gt;', $html);
    }
}
