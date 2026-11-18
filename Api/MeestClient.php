<?php

namespace Okay\Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\HttpTransport;
use Psr\Log\LoggerInterface;

/**
 * Єдина точка виходу в Meest API v3.0: заголовок token, бюджет запитів, пауза після 401 і маскування секретів.
 * Повтору після 401 немає: token кабінету не оновлюється автоматично.
 */
final class MeestClient
{
    use SendsRequests;

    public const int DEFAULT_TIMEOUT = 20;

    private const array SUCCESS = ['ok', 'success'];
    private const string PDF_SIGNATURE = '%PDF-';
    private const int MAX_PDF_BYTES = 20 * 1024 * 1024;

    private int $timeoutSeconds = self::DEFAULT_TIMEOUT;
    private ?Credentials $candidate = null;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly TokenProvider $tokens,
        private readonly RequestBudget $budget,
        private readonly CredentialsProvider $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Короткий тайм-аут для відповідей, на які хтось чекає: перевірка доступу, ціна в кошику. */
    public function quick(int $timeoutSeconds): self
    {
        $client = clone $this;
        $client->timeoutSeconds = $timeoutSeconds;

        return $client;
    }

    /**
     * Запити з обліковими даними, яких ще немає в налаштуваннях: перевірка token до збереження.
     * Відмова такого token не ставить паузу й позначку збереженому: він міг і далі працювати.
     */
    public function withCredentials(Credentials $credentials): self
    {
        $client = clone $this;
        $client->candidate = $credentials;

        return $client;
    }

    /**
     * Метод з конвертом {status, result, info}.
     *
     * @param array<mixed>|null $body
     * @return array<mixed> result; об'єкт стає асоціативним масивом, null стає []
     * @throws MeestApiException
     */
    public function call(string $httpMethod, string $path, ?array $body = null, string $slot = RequestBudget::OTHER): array
    {
        $json = $this->send($httpMethod, $path, $body, $slot);
        $status = $json['status'] ?? null;
        if (!is_string($status)) {
            throw $this->failure(ApiErrorKind::Malformed, $httpMethod, $path, 200, 'response has no status');
        }

        if (!in_array(strtolower($status), self::SUCCESS, true)) {
            $info = is_array($json['info'] ?? null) ? $json['info'] : [];
            $fieldName = is_string($info['fieldName'] ?? null) && $info['fieldName'] !== '' ? $info['fieldName'] : null;
            $message = is_string($info['message'] ?? null) && $info['message'] !== '' ? $info['message'] : null;
            throw $this->failure(ApiErrorKind::Rejected, $httpMethod, $path, 200, $message ?? "status {$status}", $fieldName, $message);
        }

        $result = $json['result'] ?? [];
        if (!is_array($result)) {
            throw $this->failure(ApiErrorKind::Malformed, $httpMethod, $path, 200, 'result is not an object');
        }

        return $result;
    }

    /**
     * Метод без конверта (`contractClientInfo`, `parcelStatus`): тіло відповіді і є результат.
     *
     * @return array<mixed>
     * @throws MeestApiException
     */
    public function plain(string $path, string $slot = RequestBudget::OTHER): array
    {
        return $this->send('GET', $path, null, $slot);
    }

    /**
     * PDF друкованої форми (стікер): байти як є. Відповідь, що не є PDF чи завелика, — Malformed: Meest може
     * віддати HTML-сторінку помилки зі статусом 200, і менеджер скачав би її як стікер.
     *
     * @param array<string, scalar> $query
     * @throws MeestApiException
     */
    public function pdf(string $path, array $query = [], string $slot = RequestBudget::OTHER): string
    {
        $path .= $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = $this->exchange('GET', $path, null, $slot, 'application/pdf');

        $size = strlen($response->body);
        if (!str_starts_with($response->body, self::PDF_SIGNATURE) || $size > self::MAX_PDF_BYTES) {
            // Тіло в лог і в текст винятку не потрапляє: лише тип і розмір.
            $type = preg_replace('/[^\w\/.+;=\- ]/', '', $response->contentType) ?? '';
            throw $this->failure(
                ApiErrorKind::Malformed,
                'GET',
                $path,
                $response->status,
                "response is not a PDF (content-type {$type}, {$size} bytes)",
                null,
                'sviat__meest__sticker_not_pdf',
                "content-type={$type} size={$size}",
            );
        }

        return $response->body;
    }

    /**
     * Meest повертає один об'єкт замість списку, коли рядок єдиний.
     *
     * @param array<mixed> $result
     * @return list<array<mixed>>
     */
    public static function rows(array $result): array
    {
        if ($result === []) {
            return [];
        }

        return array_is_list($result) ? array_values(array_filter($result, is_array(...))) : [$result];
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function send(string $httpMethod, string $path, ?array $body, string $slot): array
    {
        $response = $this->exchange($httpMethod, $path, $body, $slot, 'application/json');
        $json = $response->json();
        if ($json === null) {
            throw $this->failure(ApiErrorKind::Malformed, $httpMethod, $path, $response->status, 'response is not JSON');
        }

        return $json;
    }

    /** @param array<mixed>|null $body */
    private function exchange(string $httpMethod, string $path, ?array $body, string $slot, string $accept): HttpResponse
    {
        $encoded = null;
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw $this->failure(ApiErrorKind::Malformed, $httpMethod, $path, null, 'request body is not encodable');
            }
        }
        // token() перед budget->take(): під паузою після 401 чи без налаштувань блокування БД не береться й бюджет не витрачається.
        try {
            $token = $this->token();
            $this->budget->take($slot);
        } catch (MeestApiException $e) {
            $this->logger->warning($this->line($httpMethod, $path, null, $e->kind));
            throw $e;
        }

        $request = new HttpRequest(
            $httpMethod,
            Credentials::BASE . $path,
            ['token' => $token, 'Content-Type' => 'application/json', 'Accept' => $accept],
            $encoded,
            $this->timeoutSeconds,
        );

        try {
            $response = $this->deliver($this->http, $request, [...$this->secrets(), ...$this->rowContracts($path)]);
        } catch (MeestApiException $e) {
            if ($e->kind === ApiErrorKind::BadToken && $this->candidate === null) {
                $this->tokens->rejected();
            }
            // Статус HTTP лише в тексті винятку trait-а; окремого поля в MeestApiException немає.
            $status = preg_match('/: HTTP (\d{3})/', $e->getMessage(), $m) === 1 ? (int)$m[1] : null;
            $this->logger->warning($this->line($httpMethod, $path, $status, $e->kind, $e->fieldName));
            throw $e;
        }

        return $response;
    }

    /** @throws MeestApiException */
    private function token(): string
    {
        if ($this->candidate === null) {
            return $this->tokens->token();
        }
        if (!$this->candidate->configured()) {
            throw MeestApiException::of(ApiErrorKind::NotConfigured, 'Meest token is not set');
        }

        return $this->candidate->cabinetToken;
    }

    private function active(): Credentials
    {
        return $this->candidate ?? $this->credentials->get();
    }

    /** @return list<string> секрети і перевірюваних, і збережених облікових даних */
    private function secrets(): array
    {
        $secrets = $this->credentials->get()->secrets();
        if ($this->candidate !== null) {
            $secrets = array_values(array_unique([...$this->candidate->secrets(), ...$secrets]));
        }

        return $secrets;
    }

    private function failure(
        ApiErrorKind $kind,
        string $httpMethod,
        string $path,
        ?int $status,
        string $text,
        ?string $fieldName = null,
        ?string $apiMessage = null,
        ?string $logDetail = null,
    ): MeestApiException {
        $secrets = $this->secrets();
        $text = MeestApiException::mask($text, $secrets);
        $fieldName = $fieldName === null ? null : MeestApiException::mask($fieldName, $secrets);
        $apiMessage = $apiMessage === null ? null : MeestApiException::mask($apiMessage, $secrets);
        $this->logger->warning($this->line($httpMethod, $path, $status, $kind, $fieldName) . ($logDetail === null ? '' : " {$logDetail}"));

        return MeestApiException::of($kind, "{$httpMethod} {$this->safePath($path)}: {$text}", $fieldName, $apiMessage);
    }

    private function line(string $httpMethod, string $path, ?int $status, ApiErrorKind $kind, ?string $fieldName = null): string
    {
        $secrets = $this->secrets();
        $line = "Meest {$httpMethod} {$this->safePath($path)}"
            . ($status === null ? '' : " HTTP {$status}")
            . " kind={$kind->value}"
            . ($fieldName === null ? '' : " field={$fieldName}");

        return MeestApiException::mask($line, $secrets);
    }

    /**
     * Стікер (`?agent=`) і видалення (`parcel/{id}/{contract}`) несуть договір рядка посилки, який може
     * відрізнятися від активного: у лог і виняток він не йде, як і активний.
     */
    private function safePath(string $path): string
    {
        $path = (string)preg_replace(['/([?&]agent=)[^&]*/i', '#^(parcel/[^/?]+/)[^/?]+#'], ['$1{contract}', '$1{contract}'], $path);
        $contractId = $this->active()->contractId;

        return $contractId === '' ? $path : str_ireplace($contractId, '{contract}', $path);
    }

    /** @return list<string> */
    private function rowContracts(string $path): array
    {
        preg_match_all('/[?&]agent=([^&]+)/i', $path, $agents);
        preg_match('#^parcel/[^/?]+/([^/?]+)#', $path, $deleted);

        return array_map(rawurldecode(...), [...$agents[1], ...array_slice($deleted, 1)]);
    }
}
