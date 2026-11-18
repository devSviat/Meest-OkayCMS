<?php

namespace Modules\Sviat\Meest\Groups;

use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchKind;
use Okay\Modules\Sviat\Meest\Groups\DefaultGroups;
use PHPUnit\Framework\TestCase;

class DefaultGroupsTest extends TestCase
{
    public function testTwoGroups(): void
    {
        $rows = DefaultGroups::rows();

        self::assertCount(2, $rows);
        self::assertSame([['branch'], ['postomat']], array_column($rows, 'kinds'));
        self::assertSame([1, 2], array_column($rows, 'position'));
        self::assertSame([1, 1], array_column($rows, 'enabled'));
        foreach ($rows as $row) {
            self::assertSame(['ua', 'en', 'ru'], array_keys($row['names']));
            self::assertNotContains('', $row['names']);
        }
        self::assertSame('Відділення', $rows[0]['names']['ua']);
        self::assertSame('Поштомати', $rows[1]['names']['ua']);
    }

    public function testEveryKindHasAGroup(): void
    {
        $kinds = array_merge(...array_column(DefaultGroups::rows(), 'kinds'));
        sort($kinds);
        $all = array_map(static fn(BranchKind $kind): string => $kind->value, BranchKind::cases());
        sort($all);

        self::assertSame($all, $kinds);
    }

    public function testNameFollowsSiteLanguageAndFallsBackToUkrainian(): void
    {
        $row = DefaultGroups::rows()[1];

        self::assertSame($row['names']['en'], DefaultGroups::name($row, 'en'));
        self::assertSame($row['names']['ru'], DefaultGroups::name($row, 'ru'));
        self::assertSame('Поштомати', DefaultGroups::name($row, 'ua'));
        self::assertSame('Поштомати', DefaultGroups::name($row, ''));
        self::assertSame('Поштомати', DefaultGroups::name($row, 'pl'));
    }
}
