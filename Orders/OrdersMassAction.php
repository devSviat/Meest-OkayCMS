<?php

namespace Okay\Modules\Sviat\Meest\Orders;

use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Parcels\MassParcels;
use Okay\Modules\Sviat\Meest\Parcels\MassReport;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\StickerFile;
use Okay\Modules\Sviat\Meest\Parcels\StickerIssue;
use Okay\Modules\Sviat\Meest\Parcels\Stickers;
use Psr\Log\LoggerInterface;

/**
 * Дії списку замовлень «створити посилки» і «надрукувати стікери». Ядро не передає дії списку
 * жодному модулю, тож надіслану дію беруть із хука фільтра самого списку; звіт чекає в сесії
 * на сторінку, на яку повертають браузер.
 */
final class OrdersMassAction
{
    public const string CREATE = 'sviat_meest_create_parcels';
    public const string PRINT = 'sviat_meest_print_stickers';
    private const string SESSION_KEY = 'sviat_meest_mass';
    private const string KEY = 'sviat__meest__';
    /** Відповідь Meest на друк, що не є PDF: MeestClient::pdf кладе цей ключ в apiMessage. */
    private const string NOT_PDF = 'sviat__meest__sticker_not_pdf';

    public function __construct(
        private readonly MassParcels $mass,
        private readonly Stickers $stickers,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $sessionOk CSRF-токен адміна збігся: сток перевіряє його лише після контролера
     * @return bool|StickerFile false — дія не цього модуля; true — сторінку перезавантажити редиректом; файл — віддати браузеру
     */
    public function handle(array $post, bool $sessionOk, array &$session): bool|StickerFile
    {
        $action = $post['action'] ?? null;
        if (!in_array($action, [self::CREATE, self::PRINT], true) || !is_array($post['check'] ?? null) || !$sessionOk) {
            return false;
        }
        if ($action === self::CREATE) {
            // Закрита вкладка не має обривати запит між резервом і відповіддю Meest.
            ignore_user_abort(true);
            // Сесія лишається заблокованою до MassParcels::BUDGET_SECONDS: інші вкладки адмінки цього менеджера
            // чекають. Звіт пишеться в ту саму сесію, тож закривати її раніше означало б відкривати знову.
            $session[self::SESSION_KEY] = ['action' => self::CREATE, 'report' => $this->mass->run($post['check'])->toArray()];

            return true;
        }

        return $this->print(MassParcels::ids($post['check']), $session);
    }

    /** @param list<int> $ids */
    private function print(array $ids, array &$session): bool|StickerFile
    {
        $failed = static function (array $error) use (&$session): bool {
            $session[self::SESSION_KEY] = ['action' => self::PRINT, 'error' => $error];

            return true;
        };
        try {
            $result = $this->stickers->many($ids);
        } catch (StickerIssue $e) {
            return $failed(['key' => $e->getMessage(), 'details' => null]);
        } catch (MeestApiException $e) {
            return $failed(self::explain($e));
        } catch (\Throwable $e) {
            // Замість 500 менеджер повертається до списку з повідомленням. У лог — лише клас: текст може нести дані запиту.
            $this->logger->error('Meest mass sticker print failed: ' . $e::class);

            return $failed(['key' => self::KEY . 'mass_print_generic', 'details' => null]);
        }

        $rows = [];
        foreach ($result['skipped'] as $orderId => $key) {
            $rows[] = ['orderId' => (int)$orderId, 'kind' => 'skipped', 'key' => $key, 'value' => null];
        }
        $data = ['action' => self::PRINT, 'report' => new MassReport($rows, 0)->toArray(), 'printOf' => count($ids)];
        if ($result['file'] === null) {
            $session[self::SESSION_KEY] = $data + ['error' => ['key' => self::KEY . 'sticker_err_none', 'details' => null]];

            return true;
        }
        // Браузер бере з відповіді лише файл: пропущені замовлення список покаже, коли його відкриють знову.
        if ($rows !== []) {
            $session[self::SESSION_KEY] = $data;
        }

        return $result['file'];
    }

    /** @return array{key: string, details: ?string} */
    private static function explain(MeestApiException $e): array
    {
        if ($e->apiMessage === self::NOT_PDF) {
            return ['key' => self::NOT_PDF, 'details' => null];
        }
        $issue = new ParcelIssues()->explain($e)[0] ?? null;
        if ($issue === null) {
            return ['key' => self::KEY . 'parcel_api_rejected', 'details' => null];
        }
        // Текст Meest — лише деталь під загальною відмовою: ключем у сесії може бути тільки мовний ключ модуля.
        if (!str_starts_with($issue->text, self::KEY)) {
            return ['key' => self::KEY . 'parcel_api_rejected', 'details' => $issue->text];
        }

        return ['key' => $issue->text, 'details' => null];
    }

    /**
     * handle() для поточного запиту. Сток 4.5.3 checkSession() очищає $_POST за хибного токена,
     * тому POST читається до перевірки.
     *
     * @param \Closure(): bool $checkSession
     */
    public function handleRequest(\Closure $checkSession, array &$session): bool|StickerFile
    {
        $post = $_POST;

        return $this->handle($post, (bool)$checkSession(), $session);
    }

    /** @return ?array{action: string, report: ?MassReport, error: ?array{key: string, details: ?string}, printOf: ?int} */
    public static function take(array &$session): ?array
    {
        if (!array_key_exists(self::SESSION_KEY, $session)) {
            return null;
        }
        $data = $session[self::SESSION_KEY];
        unset($session[self::SESSION_KEY]);
        $action = is_array($data) ? ($data['action'] ?? null) : null;
        if (!in_array($action, [self::CREATE, self::PRINT], true)) {
            return null;
        }
        $report = isset($data['report']) ? MassReport::fromArray($data['report']) : null;
        $error = $data['error'] ?? null;
        $error = is_array($error) && is_string($error['key'] ?? null) && str_starts_with($error['key'], self::KEY)
            && (($error['details'] ?? null) === null || is_string($error['details']))
            ? ['key' => $error['key'], 'details' => $error['details'] ?? null]
            : null;
        $printOf = is_int($data['printOf'] ?? null) ? $data['printOf'] : null;

        return $report === null && $error === null ? null : ['action' => $action, 'report' => $report, 'error' => $error, 'printOf' => $printOf];
    }

    /**
     * @param array{action: string, report: ?MassReport, error: ?array{key: string, details: ?string}, printOf: ?int} $taken
     * @param \Closure(string): string $text повний мовний ключ → текст
     * @return array{title: ?string, rows: list<array{orderId: int, kind: string, text: string}>, untouched: ?string, error: ?string}
     */
    public static function view(array $taken, \Closure $text): array
    {
        $report = $taken['report'];
        $error = $taken['error'];
        $rows = $report === null ? [] : array_map(static function (array $row) use ($text): array {
            $value = (string)($row['value'] ?? '');
            $line = match ($row['kind']) {
                'created' => sprintf($text(self::KEY . 'mass_created'), $value),
                'skipped' => $text(self::KEY . 'mass_skipped') . ': ' . $text((string)$row['key']) . ($value !== '' ? ' ' . $value : ''),
                default => $text(self::KEY . 'mass_failed') . ': ' . $text((string)$row['key']) . ($value !== '' ? ' (' . $value . ')' : ''),
            };

            return ['orderId' => $row['orderId'], 'kind' => $row['kind'], 'text' => $line];
        }, $report->rows);

        $title = null;
        if ($report !== null && $taken['action'] === self::PRINT) {
            $of = $taken['printOf'] ?? $report->processed();
            $title = sprintf($text(self::KEY . 'mass_print_title'), max(0, $of - $report->processed()), $of);
        } elseif ($report !== null) {
            $title = sprintf($text(self::KEY . 'mass_title'), $report->created(), $report->processed());
        }

        return [
            'title' => $title,
            'rows' => $rows,
            'untouched' => $report !== null && $report->untouched > 0 ? sprintf($text(self::KEY . 'mass_untouched'), MassParcels::LIMIT, $report->untouched) : null,
            'error' => $error === null ? null : trim($text($error['key']) . ' ' . (string)$error['details']),
        ];
    }
}
