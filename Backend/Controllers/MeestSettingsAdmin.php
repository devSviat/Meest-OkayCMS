<?php

namespace Okay\Modules\Sviat\Meest\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Modules\Sviat\Meest\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Meest\Backend\KeySettings;
use Okay\Modules\Sviat\Meest\Backend\KeySettingsView;
use Okay\Modules\Sviat\Meest\Backend\SenderBranches;
use Okay\Modules\Sviat\Meest\Backend\SenderSettings;
use Okay\Modules\Sviat\Meest\Backend\ShippingSettings;
use Okay\Modules\Sviat\Meest\Cart\PopularCities;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Meest\Groups\GroupsInput;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Groups\GroupsView;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;

/**
 * Сторінка налаштувань модуля (право `delivery`). Кожен блок — окрема форма, `meest_action` каже, яка.
 * Перегляд сторінки не звертається до Meest: доступ перевіряє лише кнопка «Перевірити доступ».
 */
class MeestSettingsAdmin extends IndexAdmin
{
    /** @var array{name:string, phone:string, city_id:string, branch_id:string}|null надіслане з помилками */
    private ?array $senderInput = null;
    /** @var array<string, mixed>|null */
    private ?array $parcelInput = null;

    public function fetch(
        KeySettings $keySettings,
        KeySettingsView $keyView,
        SenderSettings $senderSettings,
        SenderBranches $senderBranches,
        ShippingSettings $shippingSettings,
        DictionaryStatusView $dictionaryView,
        DictionaryRunner $dictionaryRunner,
        KeyChecker $keyChecker,
        GroupsStore $groupsStore,
        PopularCities $popularCities,
        DictionaryCounts $dictionaryCounts,
    ) {
        if ($this->request->method('post')) {
            // Сток 4.5.3 перевіряє session_id лише після виконання контролера, тож підроблений POST уже встиг би спрацювати.
            if (!$this->request->checkSession()) {
                $this->response->setStatusCode(403);
                $this->response->setContent('Session expired', RESPONSE_TEXT);

                return;
            }
            $this->handle($keySettings, $senderSettings, $shippingSettings, $dictionaryRunner, $keyChecker, $groupsStore, $popularCities);
        }

        $contract = $keyChecker->contract();
        $sender = $this->senderInput ?? $senderSettings->read();
        $this->design->assign('meest_access', $keyView->build());
        $this->design->assign('meest_sender', [
            'values' => $sender,
            'city' => $senderBranches->city($sender['city_id']),
            'branches' => $senderBranches->forCity($sender['city_id']),
        ]);
        $parcel = $this->parcelInput ?? ShippingSettings::form($shippingSettings->read());
        $this->design->assign('meest_parcel', [
            'values' => $parcel,
            'cutoff_choices' => ShippingSettings::cutoffChoices($parcel['cutoff']),
            'forbidden' => [
                'cash' => ShippingSettings::forbidden('cash', $contract),
                'noncash' => ShippingSettings::forbidden('noncash', $contract),
            ],
        ]);
        $this->design->assign('meest_dict', $dictionaryView->build());
        $this->design->assign('meest_groups', GroupsView::vars($groupsStore->all(), $dictionaryCounts->byKind()));
        $this->design->assign('meest_popular', $popularCities->list());
        $this->response->setContent($this->design->fetch('settings.tpl'));
    }

    /** Пошук міста для вибору міста відправлення (`controller=…@senderCities&q=`). */
    public function senderCities(SenderBranches $branches)
    {
        $query = $this->request->get('q');
        $found = is_string($query) ? $branches->searchCities($query) : [];
        $this->response->setContent(json_encode(['cities' => $found], JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    /** Відділення міста для вибору відділення відправлення (`controller=…@senderBranches&city_id=`). */
    public function senderBranches(SenderBranches $branches)
    {
        $cityId = $this->request->get('city_id');
        $found = is_string($cityId) ? $branches->forCity(strtolower(trim($cityId))) : [];
        $this->response->setContent(json_encode(['branches' => $found], JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    private function handle(
        KeySettings $keySettings,
        SenderSettings $senderSettings,
        ShippingSettings $shippingSettings,
        DictionaryRunner $dictionaryRunner,
        KeyChecker $keyChecker,
        GroupsStore $groupsStore,
        PopularCities $popularCities,
    ): void {
        switch ($this->request->post('meest_action')) {
            case 'access':
            case 'check':
                $saved = $keySettings->save($_POST);
                if ($saved['errors'] !== []) {
                    $this->design->assign('meest_errors', $saved['errors']);
                    break;
                }
                // Новий token уже перевірено під час збереження: друга перевірка лише повторила б той самий запит.
                $check = $saved['check'];
                if ($check === null && $this->request->post('meest_action') === 'check') {
                    $check = $keySettings->check();
                }
                if ($check !== null) {
                    // Текст Meest показується лише у відповіді на цю перевірку й у стан не пишеться.
                    $this->design->assign('meest_check_message', $check['message']);
                }
                if ($this->request->post('meest_action') === 'access') {
                    $this->design->assign('meest_saved', true);
                }
                break;
            case 'sender':
                $errors = $senderSettings->save($_POST);
                $this->senderInput = $errors === [] ? null : SenderSettings::fromPost($_POST);
                $this->design->assign('meest_sender_errors', $errors);
                $this->design->assign('meest_sender_saved', $errors === []);
                break;
            case 'parcel_defaults':
                $errors = $shippingSettings->save($_POST, $keyChecker->contract());
                $this->parcelInput = $errors === [] ? null : ShippingSettings::formFromPost($_POST);
                $this->design->assign('meest_parcel_errors', $errors);
                $this->design->assign('meest_parcel_saved', $errors === []);
                break;
            case 'dictionaries_run':
                // Лише ставить запит у чергу: обхід країни робить завдання планувальника, а не цей запит.
                $this->design->assign('meest_dict_requested', $dictionaryRunner->requestRun() ? 'queued' : 'no_token');
                break;
            case 'groups':
                $input = GroupsInput::parse($_POST);
                if ($input['errors'] === []) {
                    $groupsStore->save($input['groups']);
                    $this->design->assign('meest_groups_saved', true);
                } else {
                    $this->design->assign('meest_groups_errors', $input['errors']);
                }
                break;
            case 'popular':
                $ids = $this->request->post('popular');
                $popularCities->save(is_array($ids) ? array_values($ids) : []);
                $this->design->assign('meest_popular_saved', true);
                break;
        }
    }
}
