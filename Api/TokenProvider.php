<?php

namespace Okay\Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Token кабінету для заголовка `token` і пауза після його відмови.
 * Строк token модуль не відстежує: прострочений дає 401, а після 401 запити 15 хв не йдуть у мережу,
 * щоб неправильний token не множив виклики Meest.
 */
final class TokenProvider
{
    public const int PAUSE = 900;

    private const string TOKEN_FAILED = 'token_failed_at';

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        private readonly AuthFlags $flags,
        private readonly \Closure $now,
    ) {
    }

    /** @throws MeestApiException NotConfigured без token; BadToken, поки триває пауза після відмови */
    public function token(): string
    {
        $credentials = $this->credentials->get();
        if (!$credentials->configured()) {
            throw MeestApiException::of(ApiErrorKind::NotConfigured, 'Meest token is not set');
        }
        if ($this->paused()) {
            throw MeestApiException::of(ApiErrorKind::BadToken, 'Meest token refused recently; paused');
        }

        return $credentials->cabinetToken;
    }

    /** Meest відповіла 401: повтор нічого не змінить, доки менеджер не збереже новий token чи не перевірить доступ. */
    public function rejected(): void
    {
        $this->flags->record(AuthFlags::TOKEN, MeestApiException::of(ApiErrorKind::BadToken, 'Meest token rejected'));
        $this->state->set(self::TOKEN_FAILED, (string)($this->now)());
    }

    /** Новий token чи ручна перевірка доступу: пауза стосувалася попереднього. */
    public function reset(): void
    {
        $this->state->set(self::TOKEN_FAILED, null);
    }

    private function paused(): bool
    {
        $failedAt = $this->state->get(self::TOKEN_FAILED);

        return $failedAt !== null && ctype_digit($failedAt) && ($this->now)() < (int)$failedAt + self::PAUSE;
    }
}
