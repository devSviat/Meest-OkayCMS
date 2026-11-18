<?php

namespace Modules\Sviat\Meest\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\TransportException;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeestClientTest extends TestCase
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

    private function client(string $token = self::TOKEN): MeestClient
    {
        $credentials = new FixedCredentials(new Credentials($token, self::CONTRACT));
        $now = fn(): int => $this->now;

        return new MeestClient(
            $this->http,
            new TokenProvider($credentials, $this->state, new AuthFlags($this->state), $now),
            new RequestBudget($this->state, new InMemoryMutex(), $now),
            $credentials,
            $this->logger,
        );
    }

    private function kindOf(callable $call): MeestApiException
    {
        try {
            $call();
        } catch (MeestApiException $e) {
            return $e;
        }
        self::fail('expected MeestApiException');
    }

    public static function statuses(): iterable
    {
        yield 'OK' => ['OK'];
        yield 'ok' => ['ok'];
        yield 'success' => ['success'];
    }

    #[DataProvider('statuses')]
    public function testStatusIsCaseInsensitive(string $status): void
    {
        $this->http->json(200, ['status' => $status, 'result' => ['branchID' => 'a']]);

        self::assertSame(['branchID' => 'a'], $this->client()->call('POST', 'branchSearch', []));
    }

    public function testErrorStatusIsRejectedWithField(): void
    {
        $this->http->json(200, ['status' => 'Error', 'info' => ['fieldName' => 'receiver.phone', 'message' => 'bad phone']]);

        $e = $this->kindOf(fn() => $this->client()->call('POST', 'parcel', ['x' => 1]));

        self::assertSame(ApiErrorKind::Rejected, $e->kind);
        self::assertSame('receiver.phone', $e->fieldName);
        self::assertSame('bad phone', $e->apiMessage);
    }

    public function testResultObjectBecomesArrayAndRowsWrapsSingleObject(): void
    {
        self::assertSame([['branchID' => 'a']], MeestClient::rows(['branchID' => 'a']));
        self::assertSame([['branchID' => 'a'], ['branchID' => 'b']], MeestClient::rows([['branchID' => 'a'], ['branchID' => 'b']]));
        self::assertSame([], MeestClient::rows([]));

        $this->http->json(200, ['status' => 'OK', 'result' => null]);
        self::assertSame([], $this->client()->call('POST', 'citySearch', []));
    }

    public function testPlainReturnsBodyWithoutEnvelope(): void
    {
        $this->http->json(200, ['contractID' => 'c', 'name' => 'n']);

        self::assertSame(['contractID' => 'c', 'name' => 'n'], $this->client()->plain('contractClientInfo/' . self::CONTRACT));
        self::assertSame('GET', $this->http->requests()[0]->method);
    }

    public static function failures(): iterable
    {
        yield 'unreachable' => [new TransportException('refused'), ApiErrorKind::Unreachable];
        yield '401' => [new HttpResponse(401, '{}'), ApiErrorKind::BadToken];
        yield '404' => [new HttpResponse(404, '{}'), ApiErrorKind::NotFound];
        yield '429' => [new HttpResponse(429, '{}'), ApiErrorKind::RateLimited];
        yield '503' => [new HttpResponse(503, 'down'), ApiErrorKind::ServerError];
        yield '400' => [new HttpResponse(400, '{"info":{"fieldName":"city","message":"no"}}'), ApiErrorKind::Rejected];
        yield 'html' => [new HttpResponse(200, '<html>'), ApiErrorKind::Malformed];
        yield 'no status' => [new HttpResponse(200, '{"result":[]}'), ApiErrorKind::Malformed];
    }

    #[DataProvider('failures')]
    public function testHttpCodesMapToKinds(HttpResponse|\Throwable $answer, ApiErrorKind $kind): void
    {
        $this->http->push($answer);

        $e = $this->kindOf(fn() => $this->client()->call('POST', 'citySearch', []));

        self::assertSame($kind, $e->kind);
        if ($kind === ApiErrorKind::Rejected) {
            self::assertSame('city', $e->fieldName);
        }
    }

    public function testPlainMalformedBody(): void
    {
        $this->http->push(new HttpResponse(200, '<html>'));

        self::assertSame(ApiErrorKind::Malformed, $this->kindOf(fn() => $this->client()->plain('parcelStatus/x'))->kind);
    }

    public function testNotConfiguredSendsNothing(): void
    {
        self::assertSame(ApiErrorKind::NotConfigured, $this->kindOf(fn() => $this->client('')->call('POST', 'citySearch', []))->kind);
        self::assertSame([], $this->http->requests());
    }

    public function testTokenInHeaderNeverInUrl(): void
    {
        $this->http->json(200, ['status' => 'OK', 'result' => []]);
        $this->client()->call('POST', 'citySearch', ['q' => 'x']);

        $request = $this->http->requests()[0];
        self::assertSame(self::TOKEN, $request->headers['token']);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertSame('https://api.meest.com/v3.0/openAPI/citySearch', $request->url);
        self::assertStringNotContainsString(self::TOKEN, $request->url);
        self::assertSame('{"q":"x"}', $request->body);
    }

    public function testCabinet401NoRetryAndPause(): void
    {
        $this->http->json(401, ['info' => ['message' => 'token expired']]);
        $client = $this->client();

        self::assertSame(ApiErrorKind::BadToken, $this->kindOf(fn() => $client->call('POST', 'parcel', []))->kind);
        self::assertCount(1, $this->http->requests());
        self::assertSame(ApiErrorKind::BadToken->value, new AuthFlags($this->state)->reason(AuthFlags::TOKEN));
        self::assertNotNull($this->state->get('token_failed_at'));

        $budgetBefore = $this->state->get('request_budget');
        for ($i = 0; $i < 10; $i++) {
            $this->now += 60;
            self::assertSame(ApiErrorKind::BadToken, $this->kindOf(fn() => $client->call('POST', 'parcel', []))->kind);
        }
        self::assertCount(1, $this->http->requests());
        self::assertSame($budgetBefore, $this->state->get('request_budget'));
    }

    public function testBudgetExhaustedSendsNothing(): void
    {
        $this->state->set('request_budget', json_encode(['window_start' => self::START, 'all' => RequestBudget::LIMIT, 'cart' => 0]));

        $e = $this->kindOf(fn() => $this->client()->call('POST', 'citySearch', []));

        self::assertSame(ApiErrorKind::RateLimited, $e->kind);
        self::assertSame([], $this->http->requests());
    }

    public function testSecretsAbsentFromExceptionsAndLog(): void
    {
        $leak = self::TOKEN . ' ' . self::CONTRACT;
        $this->http
            ->push(new TransportException("refused {$leak}"))
            ->json(400, ['info' => ['fieldName' => 'f', 'message' => "bad {$leak}"]])
            ->json(200, ['status' => 'Error', 'info' => ['fieldName' => 'f', 'message' => "bad {$leak}"]]);
        $client = $this->client();

        $messages = [
            $this->kindOf(fn() => $client->plain('contractClientInfo/' . self::CONTRACT))->getMessage(),
            $this->kindOf(fn() => $client->plain('contractClientInfo/' . self::CONTRACT))->getMessage(),
            $this->kindOf(fn() => $client->call('POST', 'parcel', []))->getMessage(),
        ];

        foreach ([...$messages, $this->logger->text()] as $text) {
            self::assertStringNotContainsString(self::TOKEN, $text);
            self::assertStringNotContainsString(self::CONTRACT, $text);
        }
        self::assertStringContainsString('contractClientInfo/{contract}', $this->logger->text());
        self::assertStringContainsString('400', $this->logger->text());
        self::assertStringContainsString('rejected', $this->logger->text());
    }

    public function testDefaultTimeoutAndQuickTimeout(): void
    {
        $this->http->json(200, ['status' => 'OK', 'result' => []])->json(200, ['contractID' => 'c']);

        $this->client()->call('POST', 'citySearch', []);
        $this->client()->quick(8)->plain('contractClientInfo/' . self::CONTRACT);

        self::assertSame(20, $this->http->requests()[0]->timeoutSeconds);
        self::assertSame(8, $this->http->requests()[1]->timeoutSeconds);
    }

    public function testCandidateCredentialsSentWithoutTouchingStoredTokenState(): void
    {
        $candidate = new Credentials('candidate-token-9', '0a1b2c3d-0000-4000-8000-0000000000bb');
        $this->http->json(401, ['info' => ['message' => 'bad token']]);

        $e = $this->kindOf(fn() => $this->client()->withCredentials($candidate)->plain('contractClientInfo/' . $candidate->contractId));

        self::assertSame(ApiErrorKind::BadToken, $e->kind);
        self::assertSame('candidate-token-9', $this->http->requests()[0]->headers['token']);
        self::assertNull($this->state->get('token_failed_at'), 'the stored token is not paused for the candidate refusal');
        self::assertSame([], new AuthFlags($this->state)->items());
        self::assertStringNotContainsString('candidate-token-9', $e->getMessage() . $this->logger->text());
        self::assertStringNotContainsString($candidate->contractId, $this->logger->text());
    }

    public function testPdfWithHtmlBodyIsMalformed(): void
    {
        $this->http->push(new HttpResponse(200, '<html><body>Error</body></html>', 'text/html; charset=utf-8'));

        $e = $this->kindOf(fn() => $this->client()->pdf('print/sticker100/abc'));

        self::assertSame(ApiErrorKind::Malformed, $e->kind);
        self::assertSame('sviat__meest__sticker_not_pdf', $e->apiMessage);
        self::assertStringContainsString('text/html', $this->logger->text());
        self::assertStringContainsString('size=31', $this->logger->text());
        self::assertStringNotContainsString('<html', $this->logger->text());
        self::assertStringNotContainsString('<html', $e->getMessage());
    }

    public function testPdfOverLimitRefused(): void
    {
        $this->http->push(new HttpResponse(200, '%PDF-1.4' . str_repeat('0', 20 * 1024 * 1024), 'application/pdf'));

        $e = $this->kindOf(fn() => $this->client()->pdf('print/sticker100/abc'));

        self::assertSame(ApiErrorKind::Malformed, $e->kind);
        self::assertSame('sviat__meest__sticker_not_pdf', $e->apiMessage);
    }

    public function testPdfPrefixAccepted(): void
    {
        $this->http->push(new HttpResponse(200, '%PDF-1.4 body', 'application/pdf'));

        self::assertSame('%PDF-1.4 body', $this->client()->pdf('print/sticker100/abc', ['agent' => self::CONTRACT]));
        $request = $this->http->requests()[0];
        self::assertSame('GET', $request->method);
        self::assertSame(self::TOKEN, $request->headers['token']);
        self::assertStringEndsWith('print/sticker100/abc?agent=' . self::CONTRACT, $request->url);
        self::assertSame('application/pdf', $request->headers['Accept']);
    }

    /** @return iterable<string, array{HttpResponse}> */
    public static function failedDeleteAnswers(): iterable
    {
        yield 'server error' => [new HttpResponse(500, '{}')];
        yield 'html' => [new HttpResponse(200, '<html>x</html>')];
        yield 'rejected' => [new HttpResponse(400, '{"info":{"message":"no"}}')];
    }

    #[DataProvider('failedDeleteAnswers')]
    public function testDeleteRowContractNeverReachesLogOrMessage(HttpResponse $answer): void
    {
        $other = '0a1b2c3d-0000-4000-8000-0000000000bb';
        $this->http->push($answer);

        $e = $this->kindOf(fn() => $this->client()->call('DELETE', 'parcel/eeeeeeee-0000-4000-8000-0000000000a1/' . $other));

        self::assertStringNotContainsString($other, $e->getMessage());
        self::assertStringNotContainsString($other, $this->logger->text());
        self::assertStringContainsString('parcel/eeeeeeee-0000-4000-8000-0000000000a1/', $e->getMessage());
    }
}
