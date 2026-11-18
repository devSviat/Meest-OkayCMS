<?php

namespace Okay\Modules\Sviat\Meest\KeyCheck;

/** Те, що модуль знає про договір. Банківські реквізити з відповіді Meest сюди не потрапляють. */
final readonly class ContractInfo
{
    public function __construct(
        public string $name,
        public string $edrpou,
        public ?string $expiresAt,
        public bool $deleted,
        public ?bool $cashAllowed,
        public ?bool $noncashAllowed,
    ) {
    }

    /** @param array<mixed> $body */
    public static function fromApi(array $body): self
    {
        $text = static fn(mixed $v): string => is_string($v) || is_int($v) ? trim((string)$v) : '';
        $fullName = $text($body['clientFullName'] ?? null);

        return new self(
            $fullName !== '' ? $fullName : $text($body['client'] ?? null),
            $text($body['edrpou'] ?? null),
            self::date($body['dateTo'] ?? null),
            self::bool($body['deleted'] ?? null) ?? false,
            self::bool($body['isAvailableCash'] ?? null),
            self::bool($body['isAvailableNoncash'] ?? null),
        );
    }

    /** Поля, яких немає в схемі Meest, бувають рядками "true"/"false"; невпізнане значення лишається невідомим. */
    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
        }

        return is_int($value) ? $value !== 0 : null;
    }

    /** Схема Meest віддає dd.MM.yyyy без часового поясу: у стан іде лише календарний день. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', trim($value), new \DateTimeZone('Europe/Kyiv'));

        return $date === false || \DateTimeImmutable::getLastErrors() !== false ? null : $date->format('Y-m-d');
    }

    public function toJson(): string
    {
        return json_encode([
            'name' => $this->name,
            'edrpou' => $this->edrpou,
            'expires_at' => $this->expiresAt,
            'deleted' => $this->deleted,
            'cash' => $this->cashAllowed,
            'noncash' => $this->noncashAllowed,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(?string $json): ?self
    {
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['edrpou'] ?? null)) {
            return null;
        }
        $flag = static fn(string $key): ?bool => is_bool($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            $data['name'],
            $data['edrpou'],
            is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            ($data['deleted'] ?? false) === true,
            $flag('cash'),
            $flag('noncash'),
        );
    }
}
