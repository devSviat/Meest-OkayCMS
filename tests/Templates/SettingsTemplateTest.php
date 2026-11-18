<?php

namespace Modules\Sviat\Meest\Templates;

require_once __DIR__ . '/../Support/SettingsPage.php';

use Modules\Sviat\Meest\Support\SettingsPage;
use PHPUnit\Framework\TestCase;

/** Сторінка налаштувань, відрендерена справжнім Smarty з даними в кожному блоці. */
class SettingsTemplateTest extends TestCase
{
    private const string XSS = '<img src=x onerror=alert(1)>';

    /** @return array<string, mixed> */
    private static function access(array $override = []): array
    {
        return array_replace(SettingsPage::defaults()['meest_access'], $override);
    }

    private static function checked(array $info = [], array $override = []): array
    {
        return array_replace([
            'auth' => 'ok', 'contract' => 'ok', 'checked_at' => '16.11.2026 14:05', 'has_problem' => false,
            'info' => array_replace([
                'name' => 'ТОВ ' . self::XSS, 'edrpou' => '00000001', 'expires_at' => '31.03.2027', 'deleted' => false,
                'cash' => 'yes', 'noncash' => 'unknown',
            ], $info),
        ], $override);
    }

    public function testAccessFormRendersAndEscapes(): void
    {
        $html = SettingsPage::render([
            'meest_access' => self::access(['saved' => true, 'contract_id' => '"><b>x', 'last_check' => self::checked()]),
            'meest_errors' => ['contract_id' => 'sviat__meest__err_contract_id'],
            'meest_check_message' => self::XSS,
        ]);

        self::assertStringContainsString('id="meest_access_form"', $html);
        self::assertSame(6, substr_count($html, 'name="session_id"'), 'one per form');
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('"><b>x', $html);
        self::assertStringContainsString('00000001', $html);
        self::assertStringContainsString('31.03.2027', $html);
        self::assertStringNotContainsString('sviat__meest__', $html, 'every translation key exists');
    }

    public function testTokenFieldIsPasswordWithEmptyValue(): void
    {
        $html = SettingsPage::render(['meest_access' => self::access(['saved' => true])]);

        self::assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="cabinet_token"[^>]*value=""/', $html);
        self::assertStringContainsString('name="clear_cabinet_token"', $html, 'a stored token can be erased');

        $empty = SettingsPage::render();
        self::assertStringNotContainsString('name="clear_cabinet_token"', $empty);
    }

    public function testFormButtonsCarryActionNames(): void
    {
        $html = SettingsPage::render();

        self::assertMatchesRegularExpression('/<button type="submit" name="meest_action" value="access"/', $html);
        self::assertMatchesRegularExpression('/<button type="submit" name="meest_action" value="check"/', $html);
    }

    public function testProblemResultIsMarked(): void
    {
        $html = SettingsPage::render([
            'meest_access' => self::access(['last_check' => self::checked([], ['auth' => 'bad_token', 'contract' => 'unchecked', 'has_problem' => true])]),
        ]);

        self::assertStringContainsString('Meest не приймає token', $html);
    }

    public function testDeletedContractIsCalledOut(): void
    {
        $html = SettingsPage::render([
            'meest_access' => self::access(['last_check' => self::checked(['deleted' => true], ['contract' => 'contract_deleted', 'has_problem' => true])]),
        ]);

        self::assertStringContainsString('Договір видалено в Meest', $html);
    }

    public function testBannerListsEveryFailedPath(): void
    {
        $html = SettingsPage::render([
            'meest_access' => self::access(['flags' => [['api' => 'token', 'reason' => 'bad_token'], ['api' => 'quote', 'reason' => 'bad_token']]]),
        ]);

        self::assertSame(1, substr_count($html, 'id="meest_auth_notice"'));
        self::assertStringContainsString('Відправлення не створюються', $html);
        self::assertStringContainsString('Покупці бачать у кошику доставку без ціни', $html);
    }

    public function testEveryLanguageRendersWithoutRawKeys(): void
    {
        foreach (['ua', 'en', 'ru'] as $language) {
            $html = SettingsPage::render([
                'meest_access' => self::access(['saved' => true, 'last_check' => self::checked(), 'flags' => [['api' => 'tracking', 'reason' => 'bad_token']]]),
                'meest_check_message' => 'x',
                'meest_saved' => true,
            ], $language);

            self::assertStringNotContainsString('sviat__meest__', $html, $language);
        }
    }

    public function testSenderParcelAndDictionaryBlocksEscapeValues(): void
    {
        $defaults = SettingsPage::defaults();
        $html = SettingsPage::render([
            'meest_sender' => [
                'values' => ['name' => self::XSS, 'phone' => '"><b>p', 'city_id' => 'c1', 'branch_id' => 'b1'],
                'city' => ['id' => 'c1', 'name' => self::XSS, 'type' => 'м.', 'region' => self::XSS],
                'branches' => [['id' => 'b1', 'number' => '1', 'title' => self::XSS, 'address' => 'x']],
            ],
            'meest_parcel' => ['values' => ['description' => self::XSS, 'notation' => '"><i>n'] + $defaults['meest_parcel']['values']] + $defaults['meest_parcel'],
            'meest_dict' => [
                'state' => 'partial', 'error' => ['kind' => 'internal', 'detail' => self::XSS],
                'skipped' => [['name' => self::XSS, 'reason' => 'implausible', 'detail' => self::XSS]],
                'empty_regions' => [self::XSS],
                'unknown_types' => [['type' => self::XSS, 'count' => 3]],
            ] + $defaults['meest_dict'],
        ]);

        self::assertStringNotContainsString(self::XSS, $html);
        self::assertStringNotContainsString('"><b>p', $html);
        self::assertStringNotContainsString('"><i>n', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('sviat__meest__', $html, 'every translation key exists');
    }

    public function testEveryFormCarriesSessionAndAction(): void
    {
        $html = SettingsPage::render();

        foreach (['sender', 'dictionaries_run', 'parcel_defaults', 'groups', 'popular'] as $action) {
            self::assertStringContainsString('name="meest_action" value="' . $action . '"', $html);
        }
        self::assertSame(6, substr_count($html, 'name="session_id"'));
    }

    public function testGroupsAndPopularCitiesRenderEscaped(): void
    {
        $vars = SettingsPage::defaults()['meest_groups'];
        $vars['groups'][0]['name'] = self::XSS;
        $html = SettingsPage::render([
            'meest_groups' => $vars,
            'meest_popular' => [['id' => '11111111-1111-4111-8111-111111111111', 'name' => self::XSS, 'type' => 'м.', 'region' => self::XSS]],
            'meest_groups_errors' => ['sviat__meest__groups_error_none'],
        ]);

        self::assertStringNotContainsString(self::XSS, $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('name="popular[]" value="11111111-1111-4111-8111-111111111111"', $html);
        self::assertStringContainsString('meest_groups_form', $html);
        self::assertStringNotContainsString('sviat__meest__', $html, 'every translation key exists');
    }

    public function testUnknownTypesAreListed(): void
    {
        $vars = SettingsPage::defaults()['meest_dict'];
        $html = SettingsPage::render(['meest_dict' => ['unknown_types' => [['type' => 'ХХХ', 'count' => 7]]] + $vars]);

        self::assertStringContainsString('ХХХ: 7', $html);
        self::assertStringNotContainsString('ХХХ: 7', SettingsPage::render());
    }

    public function testRunButtonDisabledWhileQueued(): void
    {
        $vars = SettingsPage::defaults()['meest_dict'];

        self::assertDoesNotMatchRegularExpression('/data-meest-busy="[^"]*"\s+disabled/', SettingsPage::render(['meest_dict' => $vars]));
        self::assertMatchesRegularExpression('/data-meest-busy="[^"]*"\s+disabled/', SettingsPage::render(['meest_dict' => ['queued' => true, 'can_run' => false] + $vars]));
    }

    public function testContractForbiddenPayTypeIsDisabled(): void
    {
        $parcel = SettingsPage::defaults()['meest_parcel'];
        $html = SettingsPage::render(['meest_parcel' => ['forbidden' => ['cash' => false, 'noncash' => true]] + $parcel]);

        self::assertMatchesRegularExpression('/value="noncash"[^>]*disabled/', $html);
        self::assertDoesNotMatchRegularExpression('/value="cash"[^>]*disabled/', $html);
    }

    public function testCutoffSelectKeepsSavedHour(): void
    {
        $parcel = SettingsPage::defaults()['meest_parcel'];
        $html = SettingsPage::render(['meest_parcel' => ['values' => ['cutoff' => '16:30'] + $parcel['values']] + $parcel]);

        self::assertMatchesRegularExpression('/<select id="meest_cutoff" name="cutoff"/', $html);
        self::assertMatchesRegularExpression('/value="16:30"\s+selected/', $html);
        self::assertDoesNotMatchRegularExpression('/value="16:00"\s+selected/', $html);
    }

    public function testSubmitButtonsComeBackAfterBackNavigation(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Backend/design/js/settings.js');

        self::assertStringContainsString("addEventListener('pageshow'", $script);
        self::assertStringContainsString('event.persisted', $script);
    }
    /** Браузер не дає ввести «15д» у сторону коробки; сервер однаково перевіряє все сам. */
    public function testParcelNumbersAreNumericInputs(): void
    {
        $html = SettingsPage::render();

        $input = static function (string $name) use ($html): string {
            self::assertSame(1, preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m), $name);

            return $m[0];
        };
        $weight = $input('default_weight');
        foreach (['type="number"', 'inputmode="decimal"', 'min="0.001"', 'max="99999.999"', 'step="0.001"', 'required'] as $attr) {
            self::assertStringContainsString($attr, $weight);
        }
        foreach (['box_length', 'box_width', 'box_height'] as $side) {
            foreach (['type="number"', 'inputmode="numeric"', 'min="1"', 'max="999"', 'step="1"', 'required'] as $attr) {
                self::assertStringContainsString($attr, $input($side), $side);
            }
        }
        foreach (['type="number"', 'inputmode="decimal"', 'min="0"', 'max="9999999.99"', 'step="0.01"'] as $attr) {
            self::assertStringContainsString($attr, $input('declared_value'));
        }
        self::assertStringContainsString('pattern="[0-9 \\(\\)+\\-]{10,20}"', $input('sender_phone'));
        self::assertStringContainsString('pattern="[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}"', $input('contract_id'));
    }

    public function testEveryDictionaryStateAndErrorIsTranslated(): void
    {
        $vars = SettingsPage::defaults()['meest_dict'];
        $kinds = [...array_map(static fn(\Okay\Modules\Sviat\Meest\Api\ApiErrorKind $k): string => $k->value, \Okay\Modules\Sviat\Meest\Api\ApiErrorKind::cases()), 'interrupted', 'internal', 'regions_kept', 'implausible'];
        foreach (['ua', 'en', 'ru'] as $language) {
            foreach (['none', 'running', 'ok', 'partial', 'rate_limited', 'failed', 'no_keys'] as $state) {
                $html = SettingsPage::render(['meest_dict' => ['state' => $state] + $vars], $language);
                self::assertStringNotContainsString('sviat__meest__', $html, "{$language} {$state}");
            }
            foreach ($kinds as $kind) {
                $html = SettingsPage::render(['meest_dict' => [
                    'state' => 'partial', 'error' => ['kind' => $kind, 'detail' => null],
                    'skipped' => [['name' => 'Область', 'reason' => $kind, 'detail' => null]],
                ] + $vars], $language);
                self::assertStringNotContainsString('sviat__meest__', $html, "{$language} {$kind}");
            }
        }
    }

    public function testRawErrorTextOnlyAsGreyDetail(): void
    {
        $vars = SettingsPage::defaults()['meest_dict'];
        $html = SettingsPage::render(['meest_dict' => ['state' => 'failed', 'error' => ['kind' => 'internal', 'detail' => 'SQLSTATE gone']] + $vars]);

        self::assertStringContainsString('<span class="text_grey">(SQLSTATE gone)</span>', $html);
    }

    public function testSenderBranchSelectIsSearchable(): void
    {
        self::assertMatchesRegularExpression('/<select id="meest_sender_branch_id"[^>]*data-live-search="true"[^>]*data-size="10"/', SettingsPage::render());
    }

    public function testNotCheckedLiveIsAWarningAndFreeForAnyCartAnError(): void
    {
        $html = SettingsPage::render(['meest_delivery_warnings' => ['free_for_any_cart', 'not_checked_live']], 'ua', 'delivery_free_warning.tpl');

        self::assertSame(1, substr_count($html, 'alert--error'));
        self::assertSame(1, substr_count($html, 'alert--warning'));
        self::assertMatchesRegularExpression('/alert--warning[^"]*">\s*<div class="alert__content">\s*<div class="alert__title">Доступ до Meest ще не перевірено/', $html);
    }
}
