<?php

namespace Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use PHPUnit\Framework\TestCase;

class MeestApiExceptionTest extends TestCase
{
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';

    /** Налаштування зберігають UUID у нижньому регістрі, а 1C-системи друкують GUID великими літерами. */
    public function testMaskIgnoresCase(): void
    {
        $text = MeestApiException::mask('contract ' . strtoupper(self::CONTRACT) . ' not found', [self::CONTRACT]);

        self::assertSame('contract *** not found', $text);
    }
}
