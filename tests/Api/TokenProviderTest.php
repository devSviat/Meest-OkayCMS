<?php

namespace Modules\Sviat\Meest\Api;

require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';

use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use PHPUnit\Framework\TestCase;

class TokenProviderTest extends TestCase
{
    private const int START = 1_800_000_000;
    private const string TOKEN = 'cabinet-token-abc';
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';

    private InMemoryState $state;
    private int $now = self::START;

    protected function setUp(): void
    {
        $this->state = new InMemoryState();
        $this->now = self::START;
    }

    private function provider(string $token = self::TOKEN): TokenProvider
    {
        return new TokenProvider(
            new FixedCredentials(new Credentials($token, self::CONTRACT)),
            $this->state,
            new AuthFlags($this->state),
            fn(): int => $this->now,
        );
    }

    private function expectKind(ApiErrorKind $kind, callable $call): MeestApiException
    {
        try {
            $call();
        } catch (MeestApiException $e) {
            self::assertSame($kind, $e->kind);

            return $e;
        }
        self::fail("expected {$kind->value}");
    }

    public function testCabinetTokenIsTheToken(): void
    {
        self::assertSame(self::TOKEN, $this->provider()->token());
        self::assertSame([], $this->state->values);
    }

    public function testNothingConfigured(): void
    {
        $this->expectKind(ApiErrorKind::NotConfigured, fn() => $this->provider('')->token());
    }

    public function testRejectedPausesFifteenMinutes(): void
    {
        $provider = $this->provider();
        $provider->rejected();

        self::assertSame(ApiErrorKind::BadToken->value, new AuthFlags($this->state)->reason(AuthFlags::TOKEN));
        self::assertSame((string)self::START, $this->state->get('token_failed_at'));

        foreach ([0, 1, 300, 899] as $later) {
            $this->now = self::START + $later;
            $this->expectKind(ApiErrorKind::BadToken, fn() => $provider->token());
        }

        $this->now = self::START + TokenProvider::PAUSE;
        self::assertSame(self::TOKEN, $provider->token());
    }

    public function testPauseSurvivesNewInstance(): void
    {
        $this->provider()->rejected();

        $this->expectKind(ApiErrorKind::BadToken, fn() => $this->provider()->token());
    }

    public function testResetClearsPause(): void
    {
        $provider = $this->provider();
        $provider->rejected();

        $provider->reset();

        self::assertNull($this->state->get('token_failed_at'));
        self::assertSame(self::TOKEN, $provider->token());
    }

    public function testGarbagePauseIsIgnored(): void
    {
        $this->state->set('token_failed_at', 'soon');

        self::assertSame(self::TOKEN, $this->provider()->token());
    }

    public function testStateAndExceptionsCarryNoSecrets(): void
    {
        $provider = $this->provider();
        $provider->rejected();
        $e = $this->expectKind(ApiErrorKind::BadToken, fn() => $provider->token());

        foreach ([self::TOKEN, self::CONTRACT] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($this->state->values));
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }
}
