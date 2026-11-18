<?php

namespace Okay\Modules\Sviat\Meest\Api;

final readonly class Credentials
{
    public const string CABINET_TOKEN = 'sviat__meest__cabinet_token';
    public const string CONTRACT_ID = 'sviat__meest__contract_id';

    public const string BASE = 'https://api.meest.com/v3.0/openAPI/';

    public function __construct(
        public string $cabinetToken,
        public string $contractId,
    ) {
    }

    /** @param callable(string): mixed $get */
    public static function fromSettings(callable $get): self
    {
        $text = static fn(string $key): string => is_string($value = $get($key)) ? trim($value) : '';

        return new self(
            $text(self::CABINET_TOKEN),
            $text(self::CONTRACT_ID),
        );
    }

    public function configured(): bool
    {
        return $this->cabinetToken !== '';
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return array_values(array_filter(
            [$this->cabinetToken, $this->contractId],
            static fn(string $secret): bool => $secret !== '',
        ));
    }

    /**
     * Прибирає з країв те, що додає копіювання з кабінету, месенджера чи документа:
     * пробіли Unicode, невидимі символи форматування (BOM, zero-width) і лапки будь-якого стилю.
     */
    public static function normalizeToken(string $raw): string
    {
        $edge = '[\s\p{Z}\p{Cf}"\'«»“”„‘’]+';

        return preg_replace("/^{$edge}|{$edge}$/u", '', $raw) ?? trim($raw);
    }

    public static function validContractId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($id)) === 1;
    }
}
