<?php

namespace Okay\Modules\Sviat\Meest\Quote;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;

/**
 * Ціна доставки від відділення відправника до пункту видачі з `calculate`. Один запит на розрахунок,
 * і збій ніколи не виходить назовні: оформлення замовлення має пройти й без ціни.
 */
final class QuoteService
{
    /** Покупець чекає на ціну, а типовий тайм-аут клієнта — 20 с. */
    private const int TIMEOUT = 5;

    public function __construct(
        private readonly MeestClient $client,
        private readonly AuthFlags $auth,
        private readonly CredentialsProvider $credentials,
    ) {
    }

    public function quote(QuoteRequest $request): DeliveryQuote
    {
        if ($request->config->senderBranchId === '') {
            return new DeliveryQuote(null, null, 'none', 'no_sender');
        }

        try {
            $result = $this->client->quick(self::TIMEOUT)->call('POST', 'calculate', $this->body($request), $request->slot);
        } catch (MeestApiException $e) {
            // Відмова token лишає кошик без ціни: налаштування мають про це сказати.
            $this->auth->record(AuthFlags::QUOTE, $e);

            return new DeliveryQuote(null, null, 'fallback', self::problem($e->kind));
        }

        // `result` — об'єкт (так його читає офіційний плагін) або масив з одного об'єкта (так каже схема).
        $row = MeestClient::rows($result)[0] ?? [];
        $cost = $row['costServices'] ?? null;
        if (!is_numeric($cost) || (float)$cost < 0) {
            return new DeliveryQuote(null, null, 'fallback', 'rejected');
        }
        $this->auth->clear(AuthFlags::QUOTE);

        return new DeliveryQuote(round((float)$cost, 2), self::date($row['estimatedDeliveryDate'] ?? null), 'api', null);
    }

    private static function problem(ApiErrorKind $kind): string
    {
        return match ($kind) {
            ApiErrorKind::Unreachable, ApiErrorKind::ServerError, ApiErrorKind::Malformed => 'unreachable',
            ApiErrorKind::RateLimited => 'rate_limited',
            ApiErrorKind::NotConfigured => 'not_configured',
            default => 'rejected',
        };
    }

    /** У дату йде лише розібрана дата: текст Meest ніколи не потрапляє в кошик чи замовлення. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m) === 1) {
            [$day, $month, $year] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ][\d:.]+(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value, $m) === 1) {
            [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** @return array<string, mixed> */
    private function body(QuoteRequest $request): array
    {
        $parcel = $request->parcel;
        $body = [
            'contractID' => $this->credentials->get()->contractId,
            // Модуль працює лише з онлайн-оплатою: післяплати немає.
            'COD' => 0,
            'sender' => ['service' => 'Branch', 'branchID' => $request->config->senderBranchId],
            'receiver' => ['service' => 'Branch', 'branchID' => $request->branchId],
            'placesItems' => [[
                'quantity' => 1,
                'weight' => $parcel->weightKg,
                'volume' => $parcel->volumeM3,
                'insurance' => $request->insurance,
                'length' => $parcel->lengthCm,
                'width' => $parcel->widthCm,
                'height' => $parcel->heightCm,
            ]],
        ];
        if ($request->sendingDate !== null) {
            $body['sendingDate'] = $request->sendingDate;
        }
        // Без договору Meest рахує за публічним тарифом.
        if ($body['contractID'] === '') {
            unset($body['contractID']);
        }

        return $body;
    }
}
