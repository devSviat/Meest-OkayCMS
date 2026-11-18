<?php

namespace Okay\Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Остання відмова авторизації кожного шляху, доки її не знімуть нові облікові дані чи успішна перевірка доступу.
 * Відкликаний token сам себе не виявляє: позначку показують сторінки налаштувань і замовлення.
 * Кожен шлях має власний рядок стану: кошик, планувальник і адмінка пишуть одночасно, і спільний рядок губив би чужі зміни.
 * У стан іде лише вид помилки, не текст Meest.
 */
final class AuthFlags
{
    /** Відмова token у запитах створення, друку й видалення. */
    public const string TOKEN = 'token';
    /** Калькулятор ціни в кошику. */
    public const string QUOTE = 'quote';
    public const string TRACKING = 'tracking';

    private const array STATE = [
        self::TOKEN => 'token_auth_failed',
        self::QUOTE => 'quote_auth_failed',
        self::TRACKING => 'tracking_auth_failed',
    ];
    private const array REASONS = [ApiErrorKind::BadToken->value];

    public function __construct(private readonly StateStorage $state)
    {
    }

    /** @return bool true, коли це відмова авторизації (позначку виставлено) */
    public function record(string $api, MeestApiException $e): bool
    {
        if (!in_array($e->kind->value, self::REASONS, true)) {
            return false;
        }
        $this->set($api, $e->kind->value);

        return true;
    }

    public function clear(string ...$apis): void
    {
        foreach ($apis as $api) {
            $this->set($api, null);
        }
    }

    public function reason(string $api): ?string
    {
        $reason = $this->state->get(self::STATE[$api]);

        return in_array($reason, self::REASONS, true) ? $reason : null;
    }

    /** @return list<array{api: string, reason: string}> reason: bad_token */
    public function items(): array
    {
        $items = [];
        foreach (array_keys(self::STATE) as $api) {
            $reason = $this->reason($api);
            if ($reason !== null) {
                $items[] = ['api' => $api, 'reason' => $reason];
            }
        }

        return $items;
    }

    /** Пише лише зміну: успішний запит не повинен щоразу оновлювати рядок стану. */
    private function set(string $api, ?string $reason): void
    {
        $name = self::STATE[$api];
        if ($this->state->get($name) !== $reason) {
            $this->state->set($name, $reason);
        }
    }
}
