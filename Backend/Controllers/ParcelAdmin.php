<?php

namespace Okay\Modules\Sviat\Meest\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Modules;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Parcels\Barcode;
use Okay\Modules\Sviat\Meest\Parcels\ParcelConfig;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\Meest\Parcels\ParcelInput;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssue;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelLookup;
use Okay\Modules\Sviat\Meest\Parcels\ParcelOutcome;
use Okay\Modules\Sviat\Meest\Parcels\ParcelRows;
use Okay\Modules\Sviat\Meest\Parcels\ParcelService;
use Okay\Modules\Sviat\Meest\Parcels\ParcelTracking;
use Okay\Modules\Sviat\Meest\Parcels\StickerIssue;
use Okay\Modules\Sviat\Meest\Parcels\Stickers;

/**
 * Блок посилки на сторінці замовлення надсилає сюди (`controller=Sviat.Meest.ParcelAdmin`).
 * Доступ потребує права `orders`; кожна дія — POST із CSRF-токеном. Стікер іде через цей контролер:
 * запит до Meest несе token, тож браузер не повинен бачити ні його, ні посилань Meest.
 */
class ParcelAdmin extends IndexAdmin
{
    private const string KEY = 'sviat__meest__';
    /** Відмови, де value — id іншого замовлення магазину: менеджер відкриває його, а не шукає. */
    private const array ORDER_LINKS = ['sviat__meest__parcel_err_barcode_elsewhere'];
    /** Відповідь Meest на друк, що не є PDF: MeestClient::pdf кладе цей ключ в apiMessage. */
    private const string NOT_PDF = 'sviat__meest__sticker_not_pdf';

    public function fetch(
        ParcelService $service,
        ParcelDrafts $drafts,
        ParcelLookup $lookup,
        ParcelTracking $tracking,
        Stickers $stickers,
        ParcelRows $rows,
        ParcelIssues $issues,
        BackendTranslations $translations,
        OrderDeliveryLines $lines,
        Settings $settings,
        Modules $modules,
    ) {
        if (!$this->request->method('post')) {
            $this->answer(405, ['ok' => false]);

            return;
        }
        // Сток 4.5.3 перевіряє session_id лише після виконання контролера.
        if (!$this->request->checkSession()) {
            $this->answer(403, ['ok' => false]);

            return;
        }
        // Адмінка виконує init() і вимкнених модулів, тож маршрут лишається досяжним.
        if (!$modules->isActiveModule('Sviat', 'Meest')) {
            $this->answer(200, self::view(ParcelOutcome::refused(self::KEY . 'parcel_err_module_off'), $translations));

            return;
        }
        $orderId = (int)$this->request->post('order_id', 'integer');
        $order = $orderId > 0 ? $this->entityFactory->get(OrdersEntity::class)->get($orderId) : null;
        if (!$order) {
            $this->answer(404, ['ok' => false]);

            return;
        }

        $action = (string)$this->request->post('action');
        if ($action === 'sticker') {
            $this->sticker($stickers, $rows, $issues, $translations, $orderId);

            return;
        }
        // Нова посилка — лише для замовлення, яке досі доставляє Meest; наявну можна видалити чи відв'язати й надалі.
        if (in_array($action, ['create', 'attach'], true) && $lines->ownDelivery($order) === null) {
            $this->answer(200, self::view(ParcelOutcome::refused(self::KEY . 'parcel_err_not_own'), $translations));

            return;
        }
        $outcome = match ($action) {
            'create' => $this->create($service, $drafts, $settings, $orderId),
            'lookup' => $lookup->find($orderId),
            'choose' => $this->choose($lookup, $orderId),
            'attach' => $service->attach($orderId, (string)$this->request->post('barcode')),
            'remove' => $service->remove($orderId),
            'unlink' => $service->unlink($orderId),
            'refresh' => $tracking->refresh($orderId),
            default => null,
        };
        if ($outcome === null) {
            $this->answer(400, ['ok' => false]);

            return;
        }

        $this->answer(200, self::view($outcome, $translations));
    }

    private function create(ParcelService $service, ParcelDrafts $drafts, Settings $settings, int $orderId): ParcelOutcome
    {
        $draft = $drafts->forOrder($orderId);
        if (is_string($draft)) {
            return ParcelOutcome::refused($draft);
        }
        $edited = $draft->withInput(ParcelInput::fromPost($_POST));
        if (!$edited instanceof ParcelDraft) {
            return ParcelOutcome::failed(array_map(
                static fn(string $field, string $key): ParcelIssue => new ParcelIssue($field, $key),
                array_keys($edited),
                array_values($edited),
            ));
        }

        return $service->create($orderId, $edited, ParcelConfig::fromSettings($settings->get(...)));
    }

    private function choose(ParcelLookup $lookup, int $orderId): ParcelOutcome
    {
        $parcelId = ParcelService::uuid((string)$this->request->post('parcel_id'));

        return $parcelId === null ? ParcelOutcome::refused(self::KEY . 'parcel_err_lookup_gone') : $lookup->choose($orderId, $parcelId);
    }

    private function sticker(Stickers $stickers, ParcelRows $rows, ParcelIssues $issues, BackendTranslations $translations, int $orderId): void
    {
        try {
            $file = $stickers->one($orderId);
        } catch (StickerIssue $e) {
            $this->answer(200, self::view(ParcelOutcome::refused($e->getMessage()), $translations));

            return;
        } catch (MeestApiException $e) {
            // Текст «не PDF» точніший за загальне «відповідь не прочитано»; тіла Meest у відповіді немає в жодному разі.
            $explained = $e->apiMessage === self::NOT_PDF ? [new ParcelIssue(null, self::NOT_PDF)] : $issues->explain($e);
            $this->answer(200, self::view(ParcelOutcome::failed($explained), $translations));

            return;
        }
        $barcode = Barcode::parse((string)($rows->forOrder($orderId)->barcode ?? ''));
        $name = $barcode !== null ? 'meest-' . $barcode . '.pdf' : $file->name;

        $this->response->setStatusCode(200);
        $this->response->addHeader('Content-Type: ' . $file->type);
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $name . '"');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        $this->response->setContent($file->bytes, RESPONSE_TEXT);
    }

    /**
     * Тексти вже перекладені: мовний ключ модуля перекладається, текст Meest іде як є (JS вставляє його через textContent).
     *
     * @return array{ok: bool, barcode: ?string, issues: list<array<string, ?string>>, notices: list<string>, candidates: list<array{parcel_id: string, barcode: ?string}>}
     */
    private static function view(ParcelOutcome $outcome, BackendTranslations $translations): array
    {
        $text = static fn(?string $key): ?string => $key === null ? null
            : (str_starts_with($key, self::KEY) ? (string)$translations->getTranslation($key) : $key);

        return [
            'ok' => $outcome->ok,
            'barcode' => $outcome->barcode,
            'issues' => array_map(static fn(ParcelIssue $issue): array => [
                'field' => $issue->field,
                'text' => trim($text($issue->text) . ' ' . (string)$outcome->value),
                'advice' => $text($issue->advice),
                'orderUrl' => in_array($issue->text, self::ORDER_LINKS, true) && ctype_digit((string)$outcome->value)
                    ? 'index.php?controller=OrderAdmin&id=' . (int)$outcome->value : null,
            ], $outcome->issues),
            'notices' => array_map(static fn(string $key): string => (string)$text($key), $outcome->notices),
            'candidates' => array_map(static fn(array $c): array => ['parcel_id' => $c['parcel_id'], 'barcode' => $c['barcode']], $outcome->candidates),
        ];
    }

    private function answer(int $status, array $body): void
    {
        $this->response->setStatusCode($status);
        $this->response->setContent(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), RESPONSE_JSON);
    }
}
