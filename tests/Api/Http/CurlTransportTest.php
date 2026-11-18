<?php

namespace Modules\Sviat\Meest\Api\Http;

use Okay\Modules\Sviat\Meest\Api\Http\CurlTransport;
use PHPUnit\Framework\TestCase;

class CurlTransportTest extends TestCase
{
    public function testBodyOverTwentyMegabytesIsRefused(): void
    {
        self::assertFalse(CurlTransport::tooLarge(20 * 1024 * 1024));
        self::assertTrue(CurlTransport::tooLarge(20 * 1024 * 1024 + 1));
    }
}
