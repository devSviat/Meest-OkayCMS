<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\KeyCheck\ContractInfo;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyStatus;

/** Дані шаблону блоку доступу; з token виходить лише те, чи він заданий, жодного його символу. */
final class KeySettingsView
{
    public function __construct(
        private readonly CredentialsProvider $credentials,
        private readonly KeyChecker $checker,
        private readonly AuthFlags $flags,
    ) {
    }

    /**
     * @return array{
     *     saved: bool,
     *     contract_id: string,
     *     last_check: ?array{auth: string, contract: string, checked_at: string, has_problem: bool, info: ?array<string, mixed>},
     *     flags: list<array{api: string, reason: string}>
     * }
     */
    public function build(): array
    {
        $credentials = $this->credentials->get();

        return [
            'saved' => $credentials->configured(),
            'contract_id' => $credentials->contractId,
            'last_check' => self::describe($this->checker->lastResult()),
            'flags' => $this->flags->items(),
        ];
    }

    /** @return ?array{auth: string, contract: string, checked_at: string, has_problem: bool, info: ?array<string, mixed>} */
    private static function describe(?KeyCheckResult $result): ?array
    {
        if ($result === null) {
            return null;
        }
        $failing = [KeyStatus::BadToken, KeyStatus::BadContract, KeyStatus::ContractDeleted, KeyStatus::Unreachable];

        return [
            'auth' => $result->auth->value,
            'contract' => $result->contract->value,
            'checked_at' => $result->checkedAt
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('d.m.Y H:i'),
            'has_problem' => in_array($result->auth, $failing, true) || in_array($result->contract, $failing, true),
            'info' => $result->info === null ? null : self::info($result->info),
        ];
    }

    /** @return array<string, mixed> */
    private static function info(ContractInfo $info): array
    {
        $flag = static fn(?bool $value): string => $value === null ? 'unknown' : ($value ? 'yes' : 'no');
        $expires = $info->expiresAt === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $info->expiresAt);

        return [
            'name' => $info->name,
            'edrpou' => $info->edrpou,
            'expires_at' => $expires === false || $expires === null ? null : $expires->format('d.m.Y'),
            'deleted' => $info->deleted,
            'cash' => $flag($info->cashAllowed),
            'noncash' => $flag($info->noncashAllowed),
        ];
    }
}
