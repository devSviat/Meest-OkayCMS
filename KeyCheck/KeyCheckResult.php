<?php

namespace Okay\Modules\Sviat\Meest\KeyCheck;

/** Лише види помилок і дані договору: текст відповіді Meest у результат не потрапляє. */
final readonly class KeyCheckResult
{
    public function __construct(
        public KeyStatus $auth,
        public KeyStatus $contract,
        public ?ContractInfo $info,
        public \DateTimeImmutable $checkedAt,
        public ?string $passedAt,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'auth' => $this->auth->value,
            'contract' => $this->contract->value,
            'info' => $this->info === null ? null : json_decode($this->info->toJson(), true),
            'at' => $this->checkedAt->getTimestamp(),
            'passed_at' => $this->passedAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(?string $json): ?self
    {
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !is_int($data['at'] ?? null)) {
            return null;
        }
        $auth = KeyStatus::tryFrom((string)($data['auth'] ?? ''));
        $contract = KeyStatus::tryFrom((string)($data['contract'] ?? ''));
        if ($auth === null || $contract === null) {
            return null;
        }
        $info = is_array($data['info'] ?? null) ? ContractInfo::fromJson(json_encode($data['info'])) : null;

        return new self(
            $auth,
            $contract,
            $info,
            new \DateTimeImmutable('@' . $data['at']),
            is_string($data['passed_at'] ?? null) ? $data['passed_at'] : null,
        );
    }
}
