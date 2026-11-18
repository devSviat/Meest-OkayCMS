<?php

namespace Modules\Sviat\Meest\Dictionaries;

use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BranchKindTest extends TestCase
{
    public static function rules(): array
    {
        return [
            'postomat by app' => [null, 3, BranchKind::Postomat],
            'postomat by app string' => ['', '3', BranchKind::Postomat],
            'postomat by type' => ['АПТ', null, BranchKind::Postomat],
            'branch app 1' => [null, 1, BranchKind::Branch],
            'branch app 2 string' => [null, '2', BranchKind::Branch],
            'ОВ' => ['ОВ', null, BranchKind::Branch],
            'ППВ' => ['ППВ', 1, BranchKind::Branch],
            'МППВ' => ['МППВ', 2, BranchKind::Branch],
            'point' => ['ТОЧКА', null, null],
            'point wins over app' => ['ТОЧКА', 1, null],
            'unknown' => ['XYZ', 7, null],
            'empty' => [null, null, null],
            'empty strings' => ['', '', null],
        ];
    }

    #[DataProvider('rules')]
    public function testClassify(?string $type, mixed $typeApp, ?BranchKind $expected): void
    {
        $this->assertSame($expected, BranchKind::classify($type, $typeApp));
    }
}
