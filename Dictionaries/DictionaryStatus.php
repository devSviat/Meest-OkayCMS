<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries;

use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Як пройшов останній запуск довідників; зберігається як JSON у стані модуля. Запит з адмінки лежить
 * під окремим ключем: requestRun() пише лише його, тож не перезаписує стан запуску, який веде runner.
 * `status`: ok — усі області замінено; partial — частина областей зберегла старі рядки (`skipped`:
 * id області → {name, reason}), Meest віддала урізаний перелік областей (`regions_kept`) або вся країна не дала
 * жодного пункту; rate_limited — бюджет запитів вичерпано, `pending` чекає наступного тіку;
 * failed — запуск зупинився (`error`); no_keys — немає token кабінету, Meest не викликалася.
 * `reason` і `error` для збою Meest — лише вид помилки (ApiErrorKind), без тексту Meest (S8).
 */
final class DictionaryStatus
{
    public const string OK = 'ok';
    public const string PARTIAL = 'partial';
    public const string RATE_LIMITED = 'rate_limited';
    public const string FAILED = 'failed';
    public const string NO_KEYS = 'no_keys';

    private const string STATE = 'dictionary_status';
    private const string REQUEST = 'dictionary_request';

    /** Час запиту з адмінки, який ще не обслужено; null — запиту немає. */
    public ?int $requestedAt = null;
    public ?int $runningSince = null;
    private ?string $requestId = null;
    /** id запиту, який узяв останній запуск: новіший запит, зроблений під час запуску, лишається чекати. */
    private ?string $servedRequest = null;
    /**
     * Ключі: started, finished, status, counts, skipped, pending, unknown_types, empty_regions, regions_kept, error. Тип нестрогий:
     * значення прочитане з JSON стану, тож читачі перевіряють кожне поле.
     *
     * @var array<string, mixed>|null
     */
    public ?array $lastRun = null;

    public static function load(StateStorage $state): self
    {
        $status = new self();
        $data = json_decode((string)$state->get(self::STATE), true);
        if (is_array($data)) {
            $status->runningSince = is_int($data['runningSince'] ?? null) ? $data['runningSince'] : null;
            $status->lastRun = is_array($data['lastRun'] ?? null) ? $data['lastRun'] : null;
            $status->servedRequest = is_string($data['servedRequest'] ?? null) ? $data['servedRequest'] : null;
        }
        $request = json_decode((string)$state->get(self::REQUEST), true);
        if (is_array($request) && is_string($request['id'] ?? null) && is_int($request['at'] ?? null) && $request['id'] !== $status->servedRequest) {
            $status->requestId = $request['id'];
            $status->requestedAt = $request['at'];
        }

        return $status;
    }

    /** Пише лише стан запуску; ключ запиту належить request(). */
    public function save(StateStorage $state): void
    {
        $state->set(self::STATE, json_encode([
            'runningSince' => $this->runningSince,
            'lastRun' => $this->lastRun,
            'servedRequest' => $this->servedRequest,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Один запис власного ключа, без читання стану: безпечно без блокування runner-а. */
    public static function request(StateStorage $state, int $at): void
    {
        $state->set(self::REQUEST, json_encode(['at' => $at, 'id' => bin2hex(random_bytes(8))]));
    }

    /** Запуск бере поточний запит; збережеться разом зі станом запуску. */
    public function serveRequest(): void
    {
        if ($this->requestId !== null) {
            $this->servedRequest = $this->requestId;
        }
        $this->requestId = null;
        $this->requestedAt = null;
    }

    /** @return array<string, int> `branchType`, який завантаження не впізнало → скільки таких пунктів пропущено */
    public function unknownTypes(): array
    {
        $types = $this->lastRun['unknown_types'] ?? [];

        return is_array($types) ? array_filter($types, is_int(...)) : [];
    }

    /** @return array<string, string> id → назва областей, які Meest віддала порожніми й де нічого не було збережено */
    public function emptyRegions(): array
    {
        $regions = $this->lastRun['empty_regions'] ?? [];

        return is_array($regions) ? array_filter($regions, is_string(...)) : [];
    }
}
