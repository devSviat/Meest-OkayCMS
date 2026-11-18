<?php

namespace Modules\Sviat\Meest\KeyCheck;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/ContractFixtures.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\ContractFixtures;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\TransportException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\KeyCheck\ContractInfo;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyStatus;
use PHPUnit\Framework\TestCase;

class KeyCheckerTest extends TestCase
{
    private const int START = 1_800_000_000;
    private const string TOKEN = 'cabinet-token-abc';
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';

    private FakeTransport $http;
    private InMemoryState $state;
    private ArrayLogger $logger;
    private int $now = self::START;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->state = new InMemoryState();
        $this->logger = new ArrayLogger();
        $this->now = self::START;
    }

    private function checker(string $token = self::TOKEN, string $contract = self::CONTRACT): KeyChecker
    {
        $credentials = new FixedCredentials(new Credentials($token, $contract));
        $now = fn(): int => $this->now;
        $flags = new AuthFlags($this->state);
        $tokens = new TokenProvider($credentials, $this->state, $flags, $now);
        $client = new MeestClient($this->http, $tokens, new RequestBudget($this->state, new InMemoryMutex(), $now), $credentials, $this->logger);

        return new KeyChecker($client, $tokens, $credentials, $this->state, $flags, $now);
    }

    public function testTokenThenContractInfo(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $out = $this->checker()->check();

        self::assertCount(1, $this->http->requests());
        $request = $this->http->requests()[0];
        self::assertSame('GET', $request->method);
        self::assertStringEndsWith('contractClientInfo/' . self::CONTRACT, $request->url);
        self::assertSame(self::TOKEN, $request->headers['token']);
        self::assertSame(KeyStatus::Ok, $out['result']->auth);
        self::assertSame(KeyStatus::Ok, $out['result']->contract);
        self::assertSame('ТОВ Тестовий Відправник', $out['result']->info?->name);
        self::assertSame('00000001', $out['result']->info->edrpou);
        self::assertSame('2027-03-31', $out['result']->info->expiresAt);
        self::assertNull($out['message']);
    }

    public function testNoTokenNotConfigured(): void
    {
        $out = $this->checker('')->check();

        self::assertCount(0, $this->http->requests());
        self::assertSame(KeyStatus::NotConfigured, $out['result']->auth);
    }

    public function testCheckGoesToNetworkDespitePause(): void
    {
        $this->http->json(200, ContractFixtures::body());
        $this->state->set('token_failed_at', (string)$this->now);

        $out = $this->checker()->check();

        self::assertCount(1, $this->http->requests());
        self::assertSame(KeyStatus::Ok, $out['result']->auth);
        self::assertNull($this->state->get('token_failed_at'));
    }

    public function testBadTokenSetsPauseAndFlag(): void
    {
        $this->http->push(new HttpResponse(401, '{}'));

        $out = $this->checker()->check();

        self::assertSame(KeyStatus::BadToken, $out['result']->auth);
        self::assertSame(KeyStatus::Unchecked, $out['result']->contract);
        self::assertSame('bad_token', new AuthFlags($this->state)->reason(AuthFlags::TOKEN));
        self::assertSame((string)$this->now, $this->state->get('token_failed_at'));
    }

    public function testSuccessClearsEveryAuthFlag(): void
    {
        $this->state->set('token_auth_failed', 'bad_token');
        $this->state->set('quote_auth_failed', 'bad_token');
        $this->state->set('tracking_auth_failed', 'bad_token');
        $this->http->json(200, ContractFixtures::body());

        $this->checker()->check();

        self::assertSame([], new AuthFlags($this->state)->items());
    }

    public function testCheckUsesQuickTimeoutWithoutRetry(): void
    {
        $this->http->json(503, [])->json(200, ContractFixtures::body());

        $out = $this->checker()->check();

        self::assertSame(KeyStatus::Unreachable, $out['result']->auth);
        self::assertCount(1, $this->http->requests());
        self::assertSame(KeyChecker::TIMEOUT_SECONDS, $this->http->requests()[0]->timeoutSeconds);
        self::assertSame(8, KeyChecker::TIMEOUT_SECONDS);
    }

    public function testProbeUsesCandidateAndWritesNothing(): void
    {
        $this->state->set('token_auth_failed', 'bad_token');
        $candidate = new Credentials('candidate-token-9', self::CONTRACT);
        $this->http->json(200, ContractFixtures::body());

        $out = $this->checker()->probe($candidate);

        self::assertSame(KeyStatus::Ok, $out['result']->auth);
        self::assertNotNull($out['result']->passedAt);
        self::assertSame('candidate-token-9', $this->http->requests()[0]->headers['token']);
        self::assertSame(KeyChecker::TIMEOUT_SECONDS, $this->http->requests()[0]->timeoutSeconds);
        self::assertNull($this->state->get(KeyChecker::STATE));
        self::assertNull($this->state->get(KeyChecker::CONTRACT_STATE));
        self::assertSame('bad_token', new AuthFlags($this->state)->reason(AuthFlags::TOKEN), 'flags change only when the result is remembered');
    }

    public function testRememberedProbeClearsFlagsAndStoresContract(): void
    {
        $this->state->set('quote_auth_failed', 'bad_token');
        $this->http->json(200, ContractFixtures::body());
        $checker = $this->checker();

        $checker->remember($checker->probe(new Credentials('candidate-token-9', self::CONTRACT))['result']);

        self::assertSame([], new AuthFlags($this->state)->items());
        self::assertSame(KeyStatus::Ok, $checker->lastResult()?->auth);
        self::assertSame('00000001', $checker->contract()?->edrpou);
    }

    public function testInvalidContractIdNoRequest(): void
    {
        $out = $this->checker(contract: 'not-a-uuid')->check();

        self::assertCount(0, $this->http->requests());
        self::assertSame(KeyStatus::BadContract, $out['result']->contract);
    }

    public function testUnreachable(): void
    {
        $this->http->push(new TransportException('refused'));

        $out = $this->checker()->check();

        self::assertSame(KeyStatus::Unreachable, $out['result']->auth);
    }

    public function testDeletedContractReported(): void
    {
        $this->http->json(200, ContractFixtures::body(['deleted' => true]));

        $out = $this->checker()->check();

        self::assertSame(KeyStatus::Ok, $out['result']->auth);
        self::assertSame(KeyStatus::ContractDeleted, $out['result']->contract);
        self::assertTrue($out['result']->info?->deleted);
    }

    public function testMissingIsAvailableFieldsStayNull(): void
    {
        $body = ContractFixtures::body();
        unset($body['isAvailableCash'], $body['isAvailableNoncash'], $body['dateTo']);
        $this->http->json(200, $body);

        $info = $this->checker()->check()['result']->info;

        self::assertNull($info?->cashAllowed);
        self::assertNull($info->noncashAllowed);
        self::assertNull($info->expiresAt);
    }

    public function testStringBooleansParsed(): void
    {
        $info = ContractInfo::fromApi(ContractFixtures::body([
            'isAvailableCash' => 'false',
            'isAvailableNoncash' => 'True',
        ]));

        self::assertFalse($info->cashAllowed);
        self::assertTrue($info->noncashAllowed);
    }

    public function testDateToParsedAsDayMonthYear(): void
    {
        self::assertSame('2024-02-01', ContractInfo::fromApi(ContractFixtures::body(['dateTo' => '01.02.2024']))->expiresAt);
        self::assertNull(ContractInfo::fromApi(ContractFixtures::body(['dateTo' => '2024-02-30']))->expiresAt);
        self::assertNull(ContractInfo::fromApi(ContractFixtures::body(['dateTo' => '31.02.2024']))->expiresAt);
    }

    public function testNameFallsBackToClient(): void
    {
        $info = ContractInfo::fromApi(ContractFixtures::body(['clientFullName' => '']));

        self::assertSame('Тестовий', $info->name);
    }

    public function testBankDetailsNotStored(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $this->checker()->check();

        $stored = ($this->state->get('contract') ?? '') . ($this->state->get('key_check') ?? '');
        self::assertNotSame('', $stored);
        foreach (['IBAN', 'bankName', 'MFO', 'UA0000', 'Тестовий банк', '000000"', 'sender@example', '380000000000', 'Вигадана'] as $needle) {
            self::assertStringNotContainsString($needle, $stored);
        }
    }

    public function testApiTextNotStored(): void
    {
        $this->http->json(400, ['info' => ['message' => 'Contract not found']]);

        $out = $this->checker()->check();

        self::assertSame('Contract not found', $out['message']);
        self::assertStringNotContainsString('Contract not found', $this->state->get('key_check') ?? '');
        self::assertSame(KeyStatus::BadContract, $out['result']->contract);
    }

    public function testPassedOnceSurvivesLaterFailure(): void
    {
        $this->http->json(200, ContractFixtures::body());
        $first = $this->checker()->check()['result'];
        self::assertNotNull($first->passedAt);

        $this->now += 60;
        $this->http->push(new HttpResponse(401, '{}'));
        $second = $this->checker()->check()['result'];

        self::assertSame($first->passedAt, $second->passedAt);
        self::assertSame($first->passedAt, $this->checker()->lastResult()?->passedAt);
        self::assertSame(KeyStatus::BadToken, $this->checker()->lastResult()->auth);
    }

    public function testRefreshContractWritesStateOnly(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $info = $this->checker()->refreshContract();

        self::assertSame('00000001', $info?->edrpou);
        self::assertSame('00000001', $this->checker()->contract()?->edrpou);
        self::assertNull($this->state->get('key_check'));
    }

    public function testRefreshContractFailureKeepsPreviousState(): void
    {
        $this->http->json(200, ContractFixtures::body());
        $this->checker()->refreshContract();
        $this->http->push(new TransportException('refused'));

        self::assertNull($this->checker()->refreshContract());
        self::assertSame('00000001', $this->checker()->contract()?->edrpou);
    }

    public function testResultJsonRoundTrip(): void
    {
        $this->http->json(200, ContractFixtures::body());
        $result = $this->checker()->check()['result'];

        $copy = $this->checker()->lastResult();

        self::assertEquals($result, $copy);
    }

    public function testCorruptStateReadsAsNull(): void
    {
        $this->state->set('key_check', 'oops');
        $this->state->set('contract', '[1');

        self::assertNull($this->checker()->lastResult());
        self::assertNull($this->checker()->contract());
    }
}
