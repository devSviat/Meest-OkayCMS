<?php

namespace Okay\Modules\Sviat\Meest\Groups;

use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchKind;

/** Групи, з якими стартує нове встановлення. */
final class DefaultGroups
{
    /** @return list<array{position:int, enabled:int, kinds:list<string>, names:array{ua:string, en:string, ru:string}}> */
    public static function rows(): array
    {
        return [
            [
                'position' => 1,
                'enabled' => 1,
                'kinds' => [BranchKind::Branch->value],
                'names' => ['ua' => 'Відділення', 'en' => 'Branches', 'ru' => 'Отделения'],
            ],
            [
                'position' => 2,
                'enabled' => 1,
                'kinds' => [BranchKind::Postomat->value],
                'names' => ['ua' => 'Поштомати', 'en' => 'Parcel lockers', 'ru' => 'Почтоматы'],
            ],
        ];
    }

    /** Назва мовою сайту; для іншої мови — українська, менеджер її перейменує. */
    public static function name(array $row, string $languageLabel): string
    {
        return $row['names'][$languageLabel] ?? $row['names']['ua'];
    }
}
