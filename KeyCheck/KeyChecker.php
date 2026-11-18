<?php

namespace Okay\Modules\Sviat\Meest\KeyCheck;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/** Перевіряє token кабінету й договір одним запитом `contractClientInfo`. */
final class KeyChecker
{
    public const string STATE = 'key_check';
    public const string CONTRACT_STATE = 'contract';
    /** Менеджер чекає на сторінку: перевірка має закінчитися задовго до тайм-ауту проксі. */
    public const int TIMEOUT_SECONDS = 8;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly MeestClient $client,
        private readonly TokenProvider $tokens,
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        private readonly AuthFlags $flags,
        private readonly \Closure $now,
    ) {
    }

    /**
     * Ручна перевірка йде в мережу попри паузу після 401: менеджер міг виправити token поза модулем.
     *
     * @return array{result: KeyCheckResult, message: ?string} message — текст Meest лише для показу, у стан не пишеться
     */
    public function check(): array
    {
        $this->tokens->reset();
        $out = $this->inspect($this->client->quick(self::TIMEOUT_SECONDS), $this->credentials->get(), $this->lastResult()?->passedAt);
        $this->remember($out['result']);

        return $out;
    }

    /**
     * Перевіряє облікові дані, яких ще немає в налаштуваннях, і нічого не пише: результат стає станом
     * лише через remember(), коли їх справді збережено.
     *
     * @return array{result: KeyCheckResult, message: ?string}
     */
    public function probe(Credentials $candidate): array
    {
        return $this->inspect($this->client->withCredentials($candidate)->quick(self::TIMEOUT_SECONDS), $candidate, null);
    }

    /** Прийнятий token знімає позначки відмови всіх шляхів: вони стосувалися token, який Meest тепер приймає. */
    public function remember(KeyCheckResult $result): void
    {
        if ($result->auth === KeyStatus::Ok) {
            $this->flags->clear(AuthFlags::TOKEN, AuthFlags::QUOTE, AuthFlags::TRACKING);
        }
        if ($result->info !== null) {
            $this->state->set(self::CONTRACT_STATE, $result->info->toJson());
        }
        $this->state->set(self::STATE, $result->toJson());
    }

    /** @return array{result: KeyCheckResult, message: ?string} */
    private function inspect(MeestClient $client, Credentials $credentials, ?string $passedBefore): array
    {
        $info = null;
        $message = null;

        if (!$credentials->configured()) {
            [$auth, $contract] = [KeyStatus::NotConfigured, KeyStatus::Unchecked];
        } elseif (!Credentials::validContractId($credentials->contractId)) {
            [$auth, $contract] = [KeyStatus::Unchecked, KeyStatus::BadContract];
        } else {
            try {
                $info = ContractInfo::fromApi($client->plain($this->path($credentials)));
                [$auth, $contract] = [KeyStatus::Ok, $info->deleted ? KeyStatus::ContractDeleted : KeyStatus::Ok];
            } catch (MeestApiException $e) {
                $message = $e->apiMessage;
                [$auth, $contract] = $this->statuses($e->kind);
            }
        }

        $at = ($this->now)();
        $passed = $auth === KeyStatus::Ok && $contract === KeyStatus::Ok;
        $passedAt = $passedBefore ?? ($passed ? gmdate('Y-m-d\TH:i:s\Z', $at) : null);

        return ['result' => new KeyCheckResult($auth, $contract, $info, new \DateTimeImmutable('@' . $at), $passedAt), 'message' => $message];
    }

    /** Для runner-а довідників: оновлює лише дані договору, а результат ручної перевірки не чіпає. */
    public function refreshContract(): ?ContractInfo
    {
        $credentials = $this->credentials->get();
        if (!Credentials::validContractId($credentials->contractId)) {
            return null;
        }
        try {
            $info = ContractInfo::fromApi($this->client->plain($this->path($credentials)));
        } catch (MeestApiException) {
            return null;
        }
        $this->state->set(self::CONTRACT_STATE, $info->toJson());

        return $info;
    }

    public function lastResult(): ?KeyCheckResult
    {
        return KeyCheckResult::fromJson($this->state->get(self::STATE));
    }

    public function contract(): ?ContractInfo
    {
        return ContractInfo::fromJson($this->state->get(self::CONTRACT_STATE));
    }

    private function path(Credentials $credentials): string
    {
        return 'contractClientInfo/' . strtolower($credentials->contractId);
    }

    /**
     * 404 і 400 від contractClientInfo означають, що token прийнято, а договір невідомий.
     *
     * @return array{KeyStatus, KeyStatus}
     */
    private function statuses(ApiErrorKind $kind): array
    {
        return match ($kind) {
            ApiErrorKind::BadToken => [KeyStatus::BadToken, KeyStatus::Unchecked],
            ApiErrorKind::NotConfigured => [KeyStatus::NotConfigured, KeyStatus::Unchecked],
            ApiErrorKind::NotFound, ApiErrorKind::Rejected => [KeyStatus::Ok, KeyStatus::BadContract],
            default => [KeyStatus::Unreachable, KeyStatus::Unchecked],
        };
    }
}
