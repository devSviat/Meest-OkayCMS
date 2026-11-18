<?php

namespace Okay\Modules\Sviat\Meest\Dictionaries\Rows;

enum BranchKind: string
{
    case Branch = 'branch';
    case Postomat = 'postomat';

    /** Клас береться з `branchType` і `branchTypeAPP`; UUID типів не порівнюються. «ТОЧКА» — не пункт видачі й не відправлення. */
    public static function classify(?string $type, mixed $typeApp): ?self
    {
        $type = trim((string)$type);
        if ($type === 'ТОЧКА') {
            return null;
        }

        return match ($type) {
            'АПТ' => self::Postomat,
            'ОВ', 'ППВ', 'МППВ' => self::Branch,
            default => self::byApp($typeApp),
        };
    }

    private static function byApp(mixed $typeApp): ?self
    {
        return match ((string)$typeApp) {
            '3' => self::Postomat,
            '1', '2' => self::Branch,
            default => null,
        };
    }
}
