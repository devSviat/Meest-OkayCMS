<?php

namespace Okay\Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\HttpTransport;
use Okay\Modules\Sviat\Meest\Api\Http\TransportException;

trait SendsRequests
{
    /**
     * Відповідь 2xx або типізований виняток. Секрети маскуються до створення винятку:
     * виняток транспорту не ланцюжиться, лише його замаскований текст.
     *
     * @param list<string> $secrets
     */
    private function deliver(HttpTransport $http, HttpRequest $request, array $secrets): HttpResponse
    {
        $where = MeestApiException::mask("{$request->method} {$request->url}", $secrets);

        try {
            $response = $http->send($request);
        } catch (TransportException $e) {
            throw MeestApiException::of(ApiErrorKind::Unreachable, $where . ': ' . MeestApiException::mask($e->getMessage(), $secrets));
        }

        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }

        $info = $response->json()['info'] ?? null;
        $info = is_array($info) ? $info : [];
        $apiMessage = $info['message'] ?? null;
        $apiMessage = is_string($apiMessage) && $apiMessage !== '' ? MeestApiException::mask($apiMessage, $secrets) : null;
        $fieldName = $info['fieldName'] ?? null;
        $fieldName = is_string($fieldName) && $fieldName !== '' ? MeestApiException::mask($fieldName, $secrets) : null;
        $kind = match (true) {
            $response->status === 401 => ApiErrorKind::BadToken,
            $response->status === 404 => ApiErrorKind::NotFound,
            $response->status === 429 => ApiErrorKind::RateLimited,
            $response->status >= 500 => ApiErrorKind::ServerError,
            default => ApiErrorKind::Rejected,
        };

        throw MeestApiException::of($kind, "{$where}: HTTP {$response->status}" . ($apiMessage === null ? '' : " {$apiMessage}"), $fieldName, $apiMessage);
    }
}
