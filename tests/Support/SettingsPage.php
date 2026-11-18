<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Core\BackendTranslations;

/** Сторінка налаштувань, відрендерена справжнім Smarty з тими змінними, які призначає контролер. */
final class SettingsPage
{
    /** @param array<string, mixed> $vars перекривають типові */
    public static function render(array $vars = [], string $language = 'ua', string $template = 'settings.tpl'): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/Meest/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-meest-admin-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        $lang = [];
        include $root . '/Okay/Modules/Sviat/Meest/Backend/lang/' . $language . '.php';
        foreach ($lang as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SESSION['id'] ??= 'test-session';
        $_SERVER['REQUEST_URI'] ??= '/backend/index.php';
        foreach ($vars + self::defaults() as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'meest_access' => [
                'saved' => false,
                'contract_id' => '',
                'last_check' => null,
                'flags' => [],
            ],
            'meest_sender' => [
                'values' => ['name' => '', 'phone' => '', 'city_id' => '', 'branch_id' => ''],
                'city' => null,
                'branches' => [],
            ],
            'meest_parcel' => [
                'values' => [
                    'weight' => '1', 'length' => '30', 'width' => '20', 'height' => '15', 'receiver_pay' => true, 'pay_type' => 'cash',
                    'declare_total' => true, 'declared_value' => '0', 'description' => '', 'notation' => '{order}',
                    'cutoff' => '16:00',
                ],
                'cutoff_choices' => ['15:30', '16:00', '16:30'],
                'forbidden' => ['cash' => false, 'noncash' => false],
            ],
            'meest_groups' => [
                'groups' => [
                    ['id' => 1, 'name' => 'Відділення', 'enabled' => true, 'position' => 1],
                    ['id' => 2, 'name' => 'Поштомати', 'enabled' => false, 'position' => 2],
                ],
                'kinds' => [
                    ['key' => 'branch', 'label_key' => 'sviat__meest__groups_kind_branch', 'count' => 2, 'group' => 1, 'warning' => null],
                    ['key' => 'postomat', 'label_key' => 'sviat__meest__groups_kind_postomat', 'count' => 1, 'group' => 2, 'warning' => 'group_disabled'],
                ],
            ],
            'meest_popular' => [],
            'meest_delivery_warnings' => [],
            'meest_dict' => [
                'state' => 'none', 'updated_at' => null, 'attempted_at' => null,
                'counts' => ['regions' => 0, 'cities' => 0, 'branch' => 0, 'postomat' => 0],
                'queued' => false, 'scheduler_idle' => false, 'can_run' => true, 'error' => null,
                'skipped' => [], 'empty_regions' => [], 'unknown_types' => [],
            ],
        ];
    }
}
