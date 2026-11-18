<?php

namespace Modules\Sviat\Meest\Quote;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
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
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Quote\Parcel;
use Okay\Modules\Sviat\Meest\Quote\QuoteRequest;
use Okay\Modules\Sviat\Meest\Quote\QuoteService;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuoteServiceTest extends TestCase
{
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';
    private const string SENDER = 'aaaaaaaa-0000-4000-8000-0000000000ff';
    private const string POINT = 'aaaaaaaa-0000-4000-8000-000000000001';

    private FakeTransport $transport;
    private InMemoryState $state;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
    }

    private function service(string $token = 'cabinet-token-abc'): QuoteService
    {
        $credentials = new FixedCredentials(new Credentials($token, self::CONTRACT));
        $now = fn(): int => $this->now;
        $flags = new AuthFlags($this->state);
        $client = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $this->state, $flags, $now),
            new RequestBudget($this->state, new InMemoryMutex(), $now),
            $credentials,
            new ArrayLogger(),
        );

        return new QuoteService($client, $flags, $credentials);
    }

    private static function config(array $override = []): ShippingConfig
    {
        $settings = $override + [ShippingConfig::SENDER_BRANCH_ID => self::SENDER];

        return ShippingConfig::fromSettings(static fn(string $k): mixed => $settings[$k] ?? null);
    }

    private static function request(?ShippingConfig $config = null, float $totalUah = 1500.0, float $weight = 2.5): QuoteRequest
    {
        $config ??= self::config();

        return new QuoteRequest($config, self::POINT, Parcel::fromMetrics(new CartMetrics($weight, false, $totalUah, $totalUah), $config), Parcel::insurance($config, $totalUah));
    }

    private function answer(mixed $result): void
    {
        $this->transport->json(200, ['status' => 'OK', 'result' => $result, 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => '']]);
    }

    public function testCalculateBody(): void
    {
        $this->answer(['costServices' => 80]);

        $this->service()->quote(self::request());

        $request = $this->transport->requests()[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.meest.com/v3.0/openAPI/calculate', $request->url);
        self::assertSame(5, $request->timeoutSeconds, 'the buyer is waiting');
        $body = json_decode($request->body, true);
        self::assertSame(['contractID', 'COD', 'sender', 'receiver', 'placesItems'], array_keys($body));
        self::assertEquals([
            'contractID' => self::CONTRACT,
            'COD' => 0,
            'sender' => ['service' => 'Branch', 'branchID' => self::SENDER],
            'receiver' => ['service' => 'Branch', 'branchID' => self::POINT],
            'placesItems' => [['quantity' => 1, 'weight' => 2.5, 'volume' => 0.009, 'insurance' => 1500, 'length' => 30, 'width' => 20, 'height' => 15]],
        ], $body);
    }

    public function testSendingDateGoesToCalculate(): void
    {
        $this->answer(['costServices' => 80]);
        $base = self::request();

        $this->service()->quote(new QuoteRequest($base->config, $base->branchId, $base->parcel, $base->insurance, sendingDate: '18.11.2026'));

        $body = json_decode($this->transport->requests()[0]->body, true);
        self::assertSame('18.11.2026', $body['sendingDate']);
    }

    public function testDeclaredValueFromSettingsWhenTotalOff(): void
    {
        $this->answer(['costServices' => 80]);

        $this->service()->quote(self::request(self::config([ShippingConfig::DECLARE_TOTAL => '0', ShippingConfig::DECLARED_VALUE => '300'])));

        self::assertEquals(300, json_decode($this->transport->requests()[0]->body, true)['placesItems'][0]['insurance']);
    }

    public function testParsesStringCostAndArrayResultAndDate(): void
    {
        $this->answer([['costServices' => '257.12', 'estimatedDeliveryDate' => '20.11.2026']]);

        $quote = $this->service()->quote(self::request());

        self::assertSame(257.12, $quote->price);
        self::assertSame('2026-11-20', $quote->estimatedDate);
        self::assertSame('api', $quote->source);
        self::assertNull($quote->problem);
    }

    public static function dates(): iterable
    {
        yield 'dd.MM.yyyy' => ['20.11.2026', '2026-11-20'];
        yield 'yyyy-MM-dd' => ['2026-11-20', '2026-11-20'];
        yield 'ISO' => ['2026-11-20T00:00:00', '2026-11-20'];
        yield 'garbage' => ['<b>soon</b>', null];
        yield 'impossible' => ['31.02.2026', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('dates')]
    public function testObjectResultAndDateForms(string $raw, ?string $date): void
    {
        $this->answer(['costServices' => 99.5, 'estimatedDeliveryDate' => $raw]);

        $quote = $this->service()->quote(self::request());

        self::assertSame(99.5, $quote->price);
        self::assertSame($date, $quote->estimatedDate);
    }

    public function testMissingOrBadCostIsRejected(): void
    {
        $this->answer(['estimatedDeliveryDate' => '20.11.2026']);
        self::assertSame('rejected', $this->service()->quote(self::request())->problem);

        $this->answer(['costServices' => 'abc']);
        self::assertSame('rejected', $this->service()->quote(self::request())->problem);
    }

    public function testUnreachableGivesUnpricedNotException(): void
    {
        $this->transport->push(new TransportException('connection refused'));

        $quote = $this->service()->quote(self::request());

        self::assertNull($quote->price);
        self::assertSame('unreachable', $quote->problem);
        self::assertTrue($quote->unpriced());
    }

    public function testRateLimitedIsUnpriced(): void
    {
        $this->transport->push(new HttpResponse(429, '{}'));

        $quote = $this->service()->quote(self::request());

        self::assertSame('rate_limited', $quote->problem);
        self::assertTrue($quote->unpriced());
    }

    public function testNoSenderBranchNoRequest(): void
    {
        $quote = $this->service()->quote(self::request(self::config([ShippingConfig::SENDER_BRANCH_ID => ''])));

        self::assertSame('no_sender', $quote->problem);
        self::assertSame('none', $quote->source);
        self::assertSame([], $this->transport->requests());
    }

    public function testNoTokenIsNotConfiguredWithoutRequest(): void
    {
        $quote = $this->service('')->quote(self::request());

        self::assertSame('not_configured', $quote->problem);
        self::assertTrue($quote->unpriced());
        self::assertSame([], $this->transport->requests());
    }

    public function testAuthFailureFlagsQuote(): void
    {
        $this->transport->json(401, ['status' => 'Error']);
        $quote = $this->service()->quote(self::request());
        self::assertTrue($quote->unpriced());
        self::assertSame('bad_token', new AuthFlags($this->state)->reason(AuthFlags::QUOTE));

        $this->now += TokenProvider::PAUSE + 1;
        $this->answer(['costServices' => 80]);
        self::assertSame(80.0, $this->service()->quote(self::request())->price);
        self::assertNull(new AuthFlags($this->state)->reason(AuthFlags::QUOTE));
    }
}
