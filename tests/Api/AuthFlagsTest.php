<?php

namespace Modules\Sviat\Meest\Api;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use PHPUnit\Framework\TestCase;

class AuthFlagsTest extends TestCase
{
    public function testNothingFlaggedNothingShown(): void
    {
        self::assertSame([], new AuthFlags(new InMemoryState())->items());
    }

    public function testStoresKindNotApiText(): void
    {
        $state = new InMemoryState();
        $flags = new AuthFlags($state);

        self::assertTrue($flags->record(AuthFlags::TOKEN, MeestApiException::of(ApiErrorKind::BadToken, 'x', null, 'Невірний токен')));

        self::assertSame([['api' => 'token', 'reason' => 'bad_token']], $flags->items());
        self::assertStringNotContainsString('Невірний токен', json_encode([$flags->items(), $state->values], JSON_UNESCAPED_UNICODE));
    }

    public function testOnlyAuthRefusalsAreRecorded(): void
    {
        $flags = new AuthFlags(new InMemoryState());

        self::assertFalse($flags->record(AuthFlags::QUOTE, MeestApiException::of(ApiErrorKind::Rejected, 'x')));
        self::assertFalse($flags->record(AuthFlags::TOKEN, MeestApiException::of(ApiErrorKind::NotConfigured, 'x')));
        self::assertFalse($flags->record(AuthFlags::TRACKING, MeestApiException::of(ApiErrorKind::RateLimited, 'x')));
        self::assertSame([], $flags->items());
    }

    public function testFlagsAreIndependentAndClearable(): void
    {
        $flags = new AuthFlags(new InMemoryState());
        $flags->record(AuthFlags::TOKEN, MeestApiException::of(ApiErrorKind::BadToken, 'x'));
        $flags->record(AuthFlags::TRACKING, MeestApiException::of(ApiErrorKind::BadToken, 'x'));

        $flags->clear(AuthFlags::TRACKING);

        self::assertSame([['api' => 'token', 'reason' => 'bad_token']], $flags->items());
        self::assertNull($flags->reason(AuthFlags::TRACKING));
    }

    public function testGarbageInStateIsIgnored(): void
    {
        $state = new InMemoryState();
        $state->set('token_auth_failed', '<b>x</b>');
        $state->set('quote_auth_failed', 'rejected');
        $state->set('tracking_auth_failed', 'bad_login');

        self::assertSame([], new AuthFlags($state)->items());
    }
}
