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
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use Okay\Modules\Sviat\Meest\Quote\ClientAddress;
use Okay\Modules\Sviat\Meest\Quote\QuoteBudget;
use Okay\Modules\Sviat\Meest\Quote\QuoteService;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class CartQuotesTest extends TestCase
{
    private const string POINT = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string SENDER = 'aaaaaaaa-0000-4000-8000-0000000000ff';

    private FakeTransport $transport;
    private InMemoryState $state;
    private ?string $ip = '203.0.113.7';
    /** Гривень за одиницю основної валюти магазину. */
    private float $rate = 1.0;
    private int $clock;
    private CartQuotes $quotes;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->clock = time();
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();

        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static fn(string $k): mixed => $k === ShippingConfig::SENDER_BRANCH_ID ? self::SENDER : null);

        $branches = $this->createStub(BranchEntity::class);
        $branches->method('get')->willReturnCallback(static fn($id) => $id === self::POINT ? (object)['id' => self::POINT, 'sending_only' => 0] : false);
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturn((object)['id' => 2]);
        $entities = $this->createStub(EntityFactory::class);
        $entities->method('get')->willReturnCallback(static fn(string $class): object => match ($class) {
            BranchEntity::class => $branches,
            CurrenciesEntity::class => $currencies,
        });
        $money = $this->createStub(Money::class);
        $money->method('convert')->willReturnCallback(fn(float $amount, $currencyId = null, $format = true, $revers = false): string => (string)($revers ? $amount / $this->rate : $amount * $this->rate));
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(5);
        $client = $this->createStub(ClientAddress::class);
        $client->method('address')->willReturnCallback(fn(): ?string => $this->ip);

        $credentials = new FixedCredentials(new Credentials('cabinet-token-abc', '0a1b2c3d-0000-4000-8000-00000000000a'));
        $now = static fn(): int => time();
        $flags = new AuthFlags($this->state);
        $meest = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $this->state, $flags, $now),
            new RequestBudget($this->state, new InMemoryMutex(), $now),
            $credentials,
            new ArrayLogger(),
        );

        $this->quotes = new CartQuotes(
            new QuoteService($meest, $flags, $credentials),
            $settings,
            $entities,
            $module,
            $money,
            $this->state,
            new UahTotal($entities, $money),
            new InMemoryMutex(),
            $client,
            fn(): int => $this->clock,
        );
    }

    private static function delivery(): object
    {
        return (object)['id' => 3, 'module_id' => 5, 'free_from' => 1_000_000, 'hide_front_delivery_price' => 0];
    }

    private static function cart(float $total = 500.0): object
    {
        return (object)['total_price' => $total, 'purchases' => [(object)['variant_id' => 1, 'amount' => 1, 'variant' => (object)['id' => 1, 'weight' => 1.0]]]];
    }

    private function answers(int $count, mixed $cost = 80): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->transport->json(200, ['status' => 'OK', 'result' => ['costServices' => $cost]]);
        }
    }

    public function testSameKeyServedFromSession(): void
    {
        $this->answers(1);

        self::assertSame(80.0, $this->quotes->quote(self::delivery(), self::POINT, self::cart())->price);
        self::assertSame(80.0, $this->quotes->quote(self::delivery(), self::POINT, self::cart())->price);

        self::assertCount(1, $this->transport->requests());
        self::assertSame(80.0, $this->quotes->cachedForChoice(self::delivery(), self::cart())?->price);
        self::assertSame(self::POINT, $this->quotes->chosenBranch(3));
    }

    public function testSendingDateFollowsCutoffAndRefreshesQuote(): void
    {
        $this->answers(2);
        $this->clock = new \DateTimeImmutable('2026-11-17 15:50', new \DateTimeZone('Europe/Kyiv'))->getTimestamp();

        $this->quotes->quote(self::delivery(), self::POINT, self::cart());
        $this->clock += 20 * 60;
        $this->quotes->quote(self::delivery(), self::POINT, self::cart());

        $requests = $this->transport->requests();
        self::assertCount(2, $requests, 'after the cutoff the cached date is a day short');
        self::assertSame('17.11.2026', json_decode($requests[0]->body, true)['sendingDate']);
        self::assertSame('18.11.2026', json_decode($requests[1]->body, true)['sendingDate']);
    }

    public function testThrottleTwentyPerTenMinutes(): void
    {
        $this->answers(21);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(80.0, $this->quotes->quote(self::delivery(), self::POINT, self::cart(500.0 + $i))->price, "request {$i}");
        }

        self::assertSame('busy', $this->quotes->quote(self::delivery(), self::POINT, self::cart(900.0))->problem);
        self::assertCount(20, $this->transport->requests());
    }

    public function testUsesCartSlot(): void
    {
        $this->answers(1);

        $this->quotes->quote(self::delivery(), self::POINT, self::cart());

        self::assertSame(1, json_decode((string)$this->state->get('request_budget'), true)['cart']);
    }

    public function testAdminUsesOtherSlot(): void
    {
        $this->answers(1);

        $this->quotes->quote(self::delivery(), self::POINT, self::cart(), forAdmin: true);

        self::assertSame(0, json_decode((string)$this->state->get('request_budget'), true)['cart']);
    }

    public function testPriceConvertedToMainCurrency(): void
    {
        $this->rate = 40.0;
        $this->answers(1, '257.12');

        $quote = $this->quotes->quote(self::delivery(), self::POINT, self::cart(50.0));

        self::assertSame(6.43, $quote->price, '257.12 UAH at 40 UAH per unit');
        self::assertEquals(2000, json_decode($this->transport->requests()[0]->body, true)['placesItems'][0]['insurance'], 'insurance is the cart total in hryvnias');
    }

    public function testUnknownBranchIsNotQuoted(): void
    {
        self::assertNull($this->quotes->quote(self::delivery(), 'aaaaaaaa-0000-4000-8000-000000000009', self::cart()));
        self::assertNull($this->quotes->quote(self::delivery(), '465', self::cart()));
        self::assertSame([], $this->transport->requests());
    }

    public function testForeignDeliveryIsNotQuoted(): void
    {
        self::assertNull($this->quotes->quote((object)['id' => 4, 'module_id' => 9], self::POINT, self::cart()));
    }

    public function testOneIpWithFreshSessionsHitsItsShareAndOthersStillGetQuotes(): void
    {
        $this->answers(QuoteBudget::VISITOR_LIMIT + 1);
        for ($i = 0; $i < QuoteBudget::VISITOR_LIMIT; $i++) {
            $_SESSION = [];
            self::assertNull($this->quotes->quote(self::delivery(), self::POINT, self::cart())->problem, "request {$i}");
        }

        $_SESSION = [];
        self::assertSame('busy', $this->quotes->quote(self::delivery(), self::POINT, self::cart())->problem);

        $this->ip = '203.0.113.8';
        $_SESSION = [];
        self::assertSame(80.0, $this->quotes->quote(self::delivery(), self::POINT, self::cart())->price);
    }

    public function testOrderQuotesAreNeverChargedToTheBudget(): void
    {
        $this->answers(QuoteBudget::VISITOR_LIMIT + 20);
        for ($i = 0; $i < QuoteBudget::VISITOR_LIMIT + 20; $i++) {
            $_SESSION = [];
            self::assertSame(80.0, $this->quotes->quote(self::delivery(), self::POINT, self::cart(), forOrder: true)->price);
        }

        self::assertNull($this->state->get('quote_budget'));
    }

    /** Менеджер авторизований: його розрахунок не витрачає ліміти вітрини й не чіпає сесію відвідувача. */
    public function testAdminQuoteSkipsVisitorLimitsAndSession(): void
    {
        $this->answers(25);
        $hidden = (object)(['hide_front_delivery_price' => 1] + (array)self::delivery());
        for ($i = 0; $i < 25; $i++) {
            self::assertSame(80.0, $this->quotes->quote($hidden, self::POINT, self::cart(), forAdmin: true)->price, "request {$i}");
        }

        self::assertNull($this->state->get('quote_budget'));
        self::assertSame([], $_SESSION);
    }
}
