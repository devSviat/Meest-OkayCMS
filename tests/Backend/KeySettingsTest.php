<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/ContractFixtures.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\ContractFixtures;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Backend\KeySettings;
use Okay\Modules\Sviat\Meest\Backend\KeySettingsView;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyStatus;
use PHPUnit\Framework\TestCase;

class KeySettingsTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const string TOKEN = 'cabinet-token-abc';
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';

    /** @var array<string, mixed> */
    private array $settings = [];
    private InMemoryState $state;
    private FakeTransport $http;
    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->settings = [Credentials::CABINET_TOKEN => self::TOKEN, Credentials::CONTRACT_ID => self::CONTRACT];
        $this->state = new InMemoryState();
        $this->http = new FakeTransport();
        $this->now = self::NOW;
    }

    private function provider(): CredentialsProvider
    {
        return new FixedCredentials(Credentials::fromSettings(fn(string $name) => $this->settings[$name] ?? null));
    }

    private function tokens(CredentialsProvider $provider): TokenProvider
    {
        return new TokenProvider($provider, $this->state, new AuthFlags($this->state), fn(): int => $this->now);
    }

    private function keySettings(): KeySettings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->settings[$name] ?? null);
        $settings->method('set')->willReturnCallback(function (string $name, $value): void {
            $this->settings[$name] = $value;
        });
        $provider = $this->provider();
        $now = fn(): int => $this->now;
        $flags = new AuthFlags($this->state);
        $tokens = $this->tokens($provider);
        $client = new MeestClient($this->http, $tokens, new RequestBudget($this->state, new InMemoryMutex(), $now), $provider, new ArrayLogger());
        $checker = new KeyChecker($client, $tokens, $provider, $this->state, $flags, $now);

        return new KeySettings($settings, $tokens, $this->state, $flags, $checker);
    }

    public function testEmptyFieldsKeepStoredSecrets(): void
    {
        ['errors' => $errors] = $this->keySettings()->save(['cabinet_token' => '   ']);

        self::assertSame([], $errors);
        self::assertSame(self::TOKEN, $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame(self::CONTRACT, $this->settings[Credentials::CONTRACT_ID] ?? null);
        self::assertSame([], $this->http->requests(), 'saving never calls Meest');
    }

    public function testEmptiedContractFieldClearsTheContract(): void
    {
        $this->keySettings()->save(['cabinet_token' => '', 'contract_id' => '']);

        self::assertSame('', $this->settings[Credentials::CONTRACT_ID]);
        self::assertSame(self::TOKEN, $this->settings[Credentials::CABINET_TOKEN]);
    }

    public function testPostedTokenReplacesStoredOneTrimmed(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $this->keySettings()->save(['cabinet_token' => '  new-token-1  ']);

        self::assertSame('new-token-1', $this->settings[Credentials::CABINET_TOKEN]);
    }

    public function testNewTokenCheckedBeforeSaving(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $out = $this->keySettings()->save(['cabinet_token' => 'new-token-1']);

        self::assertSame([], $out['errors']);
        self::assertCount(1, $this->http->requests());
        self::assertSame('new-token-1', $this->http->requests()[0]->headers['token']);
        self::assertSame(KeyChecker::TIMEOUT_SECONDS, $this->http->requests()[0]->timeoutSeconds);
        self::assertSame('new-token-1', $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame(KeyStatus::Ok, $out['check']['result']->auth ?? null);
        self::assertStringContainsString('"auth":"ok"', (string)$this->state->get(KeyChecker::STATE), 'the check describes the saved token');
        self::assertSame('00000001', \Okay\Modules\Sviat\Meest\KeyCheck\ContractInfo::fromJson($this->state->get(KeyChecker::CONTRACT_STATE))?->edrpou);
    }

    public function testRejectedTokenKeepsThePreviousOne(): void
    {
        $this->state->set(KeyChecker::STATE, '{"keep":true}');
        $this->http->json(401, ['info' => ['message' => 'token invalid']]);

        $out = $this->keySettings()->save(['cabinet_token' => 'mistyped-token', 'contract_id' => '0a1b2c3d-0000-4000-8000-0000000000bb']);

        self::assertSame(['cabinet_token' => 'sviat__meest__err_token_rejected'], $out['errors']);
        self::assertSame(self::TOKEN, $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame(self::CONTRACT, $this->settings[Credentials::CONTRACT_ID], 'a refused form saves nothing');
        self::assertSame('{"keep":true}', $this->state->get(KeyChecker::STATE));
        self::assertNull($this->state->get('token_failed_at'), 'the stored token is not paused');
        self::assertSame([], new AuthFlags($this->state)->items());
    }

    public function testUnreachableMeestDoesNotBlockSaving(): void
    {
        $this->http->push(new \Okay\Modules\Sviat\Meest\Api\Http\TransportException('timeout'));

        $out = $this->keySettings()->save(['cabinet_token' => 'new-token-1']);

        self::assertSame([], $out['errors']);
        self::assertSame('new-token-1', $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame(KeyStatus::Unreachable, $out['check']['result']->auth ?? null);
    }

    public function testUnknownContractDoesNotBlockSavingTheToken(): void
    {
        $this->http->json(404, []);

        $out = $this->keySettings()->save(['cabinet_token' => 'new-token-1']);

        self::assertSame([], $out['errors']);
        self::assertSame('new-token-1', $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame(KeyStatus::BadContract, $out['check']['result']->contract ?? null);
    }

    public function testPastedTokenLosesQuotesBomAndZeroWidth(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $this->keySettings()->save(['cabinet_token' => "\u{FEFF} «new-token-1»\u{200B}\u{00A0}"]);

        self::assertSame('new-token-1', $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame('new-token-1', $this->http->requests()[0]->headers['token']);
    }

    public function testSameTokenAgainIsNotRechecked(): void
    {
        $out = $this->keySettings()->save(['cabinet_token' => self::TOKEN]);

        self::assertSame([], $this->http->requests());
        self::assertNull($out['check']);
    }

    public function testClearBoxErasesOnlyItsField(): void
    {
        $this->keySettings()->save(['cabinet_token' => '', 'clear_cabinet_token' => '1']);

        self::assertSame('', $this->settings[Credentials::CABINET_TOKEN]);
        self::assertSame(self::CONTRACT, $this->settings[Credentials::CONTRACT_ID]);
    }

    public function testBadContractIdRefused(): void
    {
        ['errors' => $errors] = $this->keySettings()->save(['cabinet_token' => 'new-token-1', 'contract_id' => 'not-a-uuid']);

        self::assertSame(['contract_id' => 'sviat__meest__err_contract_id'], $errors);
        self::assertSame(self::TOKEN, $this->settings[Credentials::CABINET_TOKEN], 'a refused form saves nothing');
        self::assertSame(self::CONTRACT, $this->settings[Credentials::CONTRACT_ID]);
    }

    public function testContractIdIsLowercasedBeforeStoring(): void
    {
        ['errors' => $errors] = $this->keySettings()->save(['contract_id' => ' ' . strtoupper(self::CONTRACT) . ' ']);

        self::assertSame([], $errors);
        self::assertSame(self::CONTRACT, $this->settings[Credentials::CONTRACT_ID]);
    }

    public function testTokenWithInnerWhitespaceRefused(): void
    {
        ['errors' => $errors] = $this->keySettings()->save(['cabinet_token' => "abc\r\nX-Injected: 1"]);

        self::assertSame(['cabinet_token' => 'sviat__meest__err_token_chars'], $errors);
        self::assertSame(self::TOKEN, $this->settings[Credentials::CABINET_TOKEN]);
    }

    public function testCredentialChangeResetsTokenPausesAndCheck(): void
    {
        $provider = $this->provider();
        $tokens = $this->tokens($provider);
        $tokens->rejected();
        $this->state->set(KeyChecker::STATE, '{"stale":true}');
        $this->state->set(KeyChecker::CONTRACT_STATE, '{"stale":true}');
        $this->now += 10;
        $this->http->push(new \Okay\Modules\Sviat\Meest\Api\Http\TransportException('timeout'));

        $this->keySettings()->save(['cabinet_token' => 'replacement-token']);

        self::assertStringNotContainsString('stale', (string)$this->state->get(KeyChecker::STATE), 'old check result replaced');
        self::assertNull($this->state->get(KeyChecker::CONTRACT_STATE), 'contract data of the old credentials erased');
        self::assertSame([], new AuthFlags($this->state)->items(), 'failure banner of the old token cleared');
        self::assertSame('replacement-token', $this->tokens($this->provider())->token(), 'pause is gone');
    }

    public function testContractChangeAlsoErasesCheck(): void
    {
        $this->state->set(KeyChecker::STATE, '{"stale":true}');

        $this->keySettings()->save(['contract_id' => '0a1b2c3d-0000-4000-8000-0000000000bb']);

        self::assertNull($this->state->get(KeyChecker::STATE));
        self::assertSame([], $this->http->requests(), 'only a new token is checked before saving');
    }

    public function testSavingTheSameValuesKeepsCheckResult(): void
    {
        $this->state->set(KeyChecker::STATE, '{"keep":true}');
        new AuthFlags($this->state)->record(AuthFlags::TOKEN, \Okay\Modules\Sviat\Meest\Api\MeestApiException::of(\Okay\Modules\Sviat\Meest\Api\ApiErrorKind::BadToken, 'x'));

        $this->keySettings()->save(['cabinet_token' => '', 'contract_id' => strtoupper(self::CONTRACT)]);

        self::assertSame('{"keep":true}', $this->state->get(KeyChecker::STATE));
        self::assertCount(1, new AuthFlags($this->state)->items());
    }

    public function testCheckRunsAgainstMeestAndReturnsText(): void
    {
        $this->http->json(400, ['info' => ['message' => 'Contract not found']]);

        $out = $this->keySettings()->check();

        self::assertSame('Contract not found', $out['message']);
        self::assertSame(KeyStatus::BadContract, $out['result']->contract);
        self::assertStringNotContainsString('Contract not found', $this->state->get(KeyChecker::STATE) ?? '');
    }

    public function testViewNeverCarriesTheToken(): void
    {
        $this->http->json(200, ContractFixtures::body());
        $settings = $this->keySettings();
        $settings->check();
        $provider = $this->provider();
        $checker = new KeyChecker(
            new MeestClient($this->http, $this->tokens($provider), new RequestBudget($this->state, new InMemoryMutex(), fn(): int => $this->now), $provider, new ArrayLogger()),
            $this->tokens($provider),
            $provider,
            $this->state,
            new AuthFlags($this->state),
            fn(): int => $this->now,
        );

        $view = new KeySettingsView($provider, $checker, new AuthFlags($this->state))->build();

        self::assertTrue($view['saved']);
        self::assertSame(self::CONTRACT, $view['contract_id']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($view, JSON_THROW_ON_ERROR));
        self::assertSame('ТОВ Тестовий Відправник', $view['last_check']['info']['name']);
        self::assertSame('31.03.2027', $view['last_check']['info']['expires_at']);
        self::assertSame('yes', $view['last_check']['info']['cash']);
        self::assertSame('no', $view['last_check']['info']['noncash']);
        self::assertFalse($view['last_check']['has_problem']);
    }

    public function testViewReportsMissingCheckAndToken(): void
    {
        $this->settings = [];
        $provider = $this->provider();
        $tokens = $this->tokens($provider);
        $checker = new KeyChecker(
            new MeestClient($this->http, $tokens, new RequestBudget($this->state, new InMemoryMutex(), fn(): int => $this->now), $provider, new ArrayLogger()),
            $tokens,
            $provider,
            $this->state,
            new AuthFlags($this->state),
            fn(): int => $this->now,
        );

        $view = new KeySettingsView($provider, $checker, new AuthFlags($this->state))->build();

        self::assertFalse($view['saved']);
        self::assertSame('', $view['contract_id']);
        self::assertNull($view['last_check']);
        self::assertSame([], $view['flags']);
    }
}
