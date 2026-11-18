<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\SecretSetting;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyStatus;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/** Блок доступу сторінки налаштувань: новий token перевіряється до запису, збережений — кнопкою «Перевірити доступ». */
final class KeySettings
{
    public const string TOKEN_FIELD = 'cabinet_token';
    public const string CONTRACT_FIELD = 'contract_id';

    public function __construct(
        private readonly Settings $settings,
        private readonly TokenProvider $tokens,
        private readonly StateStorage $state,
        private readonly AuthFlags $flags,
        private readonly KeyChecker $checker,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{errors: array<string, string>, check: ?array{result: KeyCheckResult, message: ?string}}
     *     errors — поле → мовний ключ помилки; якщо вони є, нічого не зберігається; check — перевірка нового token
     */
    public function save(array $post): array
    {
        $postedToken = is_string($post[self::TOKEN_FIELD] ?? null) ? Credentials::normalizeToken($post[self::TOKEN_FIELD]) : '';
        $storedContract = (string)$this->settings->get(Credentials::CONTRACT_ID);
        $contract = array_key_exists(self::CONTRACT_FIELD, $post)
            ? strtolower(is_string($post[self::CONTRACT_FIELD]) ? trim($post[self::CONTRACT_FIELD]) : '')
            : $storedContract;

        $errors = [];
        // Token іде в заголовок: пробіл чи перенесення рядка всередині зламали б запит або дозволили б дописати інший заголовок.
        if ($postedToken !== '' && preg_match('/^[\x21-\x7E]+$/', $postedToken) !== 1) {
            $errors[self::TOKEN_FIELD] = 'sviat__meest__err_token_chars';
        }
        if ($contract !== '' && !Credentials::validContractId($contract)) {
            $errors[self::CONTRACT_FIELD] = 'sviat__meest__err_contract_id';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'check' => null];
        }

        $storedToken = (string)$this->settings->get(Credentials::CABINET_TOKEN);
        $token = SecretSetting::next($storedToken, $postedToken, $post['clear_' . self::TOKEN_FIELD] ?? null);
        $check = null;
        if ($token !== '' && $token !== $storedToken) {
            $check = $this->checker->probe(new Credentials($token, $contract));
            // Token, який Meest відхиляє, не повинен замінити той, що ще може працювати: одна хибна вставка
            // лишила б кошик без ціни. Недоступна Meest чи невідомий договір збереженню не заважають.
            if ($check['result']->auth === KeyStatus::BadToken) {
                return ['errors' => [self::TOKEN_FIELD => 'sviat__meest__err_token_rejected'], 'check' => null];
            }
        }

        if ($token !== $storedToken) {
            $this->settings->set(Credentials::CABINET_TOKEN, $token);
        }
        if ($contract !== $storedContract) {
            $this->settings->set(Credentials::CONTRACT_ID, $contract);
        }
        if ($token !== $storedToken || $contract !== $storedContract) {
            $this->forgetPreviousCredentials();
        }
        if ($check !== null) {
            $this->checker->remember($check['result']);
        }

        return ['errors' => [], 'check' => $check];
    }

    /**
     * Звертається до Meest; викликається лише кнопкою «Перевірити доступ».
     *
     * @return array{result: KeyCheckResult, message: ?string}
     */
    public function check(): array
    {
        return $this->checker->check();
    }

    /** Пауза, позначка відмови, результат перевірки й дані договору описували попередні облікові дані. */
    private function forgetPreviousCredentials(): void
    {
        $this->tokens->reset();
        $this->flags->clear(AuthFlags::TOKEN, AuthFlags::QUOTE, AuthFlags::TRACKING);
        $this->state->set(KeyChecker::STATE, null);
        $this->state->set(KeyChecker::CONTRACT_STATE, null);
    }
}
