<?php

namespace Modules\Sviat\Meest\Controllers;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Core\Cart;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\UserHelper;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Cart\BranchList;
use Okay\Modules\Sviat\Meest\Cart\CitySearch;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Controllers\CartController;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\GroupEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use Okay\Modules\Sviat\Meest\Quote\ClientAddress;
use Okay\Modules\Sviat\Meest\Quote\QuoteService;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class CartControllerTest extends TestCase
{
    private const string TOKEN = 'cabinet-token-secret-77';
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';
    private const string POINT = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string SENDING_ONLY = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const string SENDER = 'aaaaaaaa-0000-4000-8000-0000000000ff';

    private FakeTransport $transport;
    private InMemoryState $state;
    private EntityFactory $entities;
    private CartQuotes $quotes;
    private GroupsStore $groups;
    private array $server;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_GET = [];
        $this->server = $_SERVER;
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();

        $rows = [
            self::POINT => (object)['id' => self::POINT, 'city_id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'kind' => 'branch', 'sending_only' => 0, 'schedule_json' => null],
            self::SENDING_ONLY => (object)['id' => self::SENDING_ONLY, 'city_id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'kind' => 'branch', 'sending_only' => 1, 'schedule_json' => null],
        ];
        $branches = $this->createStub(BranchEntity::class);
        $branches->method('get')->willReturnCallback(static fn($id) => $rows[$id] ?? false);
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturn((object)['id' => 3, 'module_id' => 5, 'enabled' => 1, 'free_from' => 1_000_000, 'hide_front_delivery_price' => 0]);
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturn((object)['id' => 2]);
        $currencies->method('getMainCurrency')->willReturn((object)['id' => 2, 'sign' => '₴']);
        // mappedBy() у ядрі final: заглушка — підклас без бази.
        $payments = new class extends PaymentsEntity {
            public function __construct()
            {
            }

            public function find(array $filter = [])
            {
                return [];
            }
        };
        $groupEntity = $this->createStub(GroupEntity::class);
        $groupEntity->method('find')->willReturn([(object)['id' => 1, 'enabled' => 1, 'kinds' => '["branch"]', 'name' => 'Відділення']]);
        $this->entities = $this->createStub(EntityFactory::class);
        $this->entities->method('get')->willReturnCallback(static fn(string $class): object => match ($class) {
            BranchEntity::class => $branches,
            DeliveriesEntity::class => $deliveries,
            CurrenciesEntity::class => $currencies,
            PaymentsEntity::class => $payments,
            GroupEntity::class => $groupEntity,
        });
        $this->groups = new GroupsStore($this->entities);

        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static fn(string $k): mixed => $k === ShippingConfig::SENDER_BRANCH_ID ? self::SENDER : null);
        $money = $this->createStub(Money::class);
        $money->method('convert')->willReturnCallback(static fn(float $amount): string => (string)$amount);
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(5);
        $credentials = new FixedCredentials(new Credentials(self::TOKEN, self::CONTRACT));
        $now = static fn(): int => time();
        $flags = new AuthFlags($this->state);
        $client = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $this->state, $flags, $now),
            new RequestBudget($this->state, new InMemoryMutex(), $now),
            $credentials,
            new ArrayLogger(),
        );
        $this->quotes = new CartQuotes(new QuoteService($client, $flags, $credentials), $settings, $this->entities, $module, $money, $this->state, new UahTotal($this->entities, $money), new InMemoryMutex(), new ClientAddress(), $now);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
    }

    private static function request(): Request
    {
        return new \ReflectionClass(Request::class)->newInstanceWithoutConstructor();
    }

    /** @return array{0: Response, 1: \ArrayObject} відповідь і записані в неї статус та JSON */
    private function response(): array
    {
        $seen = new \ArrayObject(['status' => 200, 'json' => null]);
        $response = $this->createStub(Response::class);
        $response->method('setStatusCode')->willReturnCallback(static function ($code) use ($seen, &$response) {
            $seen['status'] = $code;

            return $response;
        });
        $response->method('setContent')->willReturnCallback(static function ($content) use ($seen, &$response) {
            $seen['json'] = $content;

            return $response;
        });

        return [$response, $seen];
    }

    private static function cart(): Cart
    {
        return new class extends Cart {
            public function __construct()
            {
                $this->total_price = 500.0;
                $this->purchases = [(object)['variant_id' => 1, 'amount' => 1, 'variant' => (object)['id' => 1, 'weight' => 1.0]]];
            }

            public function get(): Cart
            {
                return $this;
            }
        };
    }

    private static function userHelper(): UserHelper
    {
        return new class extends UserHelper {
            public function __construct()
            {
            }

            public function mergeCart($onlyToLocal = false)
            {
            }
        };
    }

    private function deliveriesHelper(): DeliveriesHelper
    {
        $helper = $this->createStub(DeliveriesHelper::class);
        $helper->method('getCartDeliveriesList')->willReturnCallback(static fn(): array => [3 => (object)['id' => 3, 'price' => 80.0, 'delivery_price_text' => '80 ₴', 'total_price_with_delivery' => 580.0, 'is_free_delivery' => false]]);

        return $helper;
    }

    private function quote(array $get): \ArrayObject
    {
        $_GET = $get;
        [$response, $seen] = $this->response();
        new CartController()->quote(self::request(), $response, $this->quotes, $this->groups, self::cart(), self::userHelper(), $this->entities, $this->deliveriesHelper(), $this->createStub(FrontTranslations::class));

        return $seen;
    }

    private function answer(): void
    {
        $this->transport->json(200, ['status' => 'OK', 'result' => ['costServices' => 80, 'estimatedDeliveryDate' => '20.11.2026']]);
    }

    public function testValidBranchIsQuoted(): void
    {
        $this->answer();

        $seen = $this->quote(['delivery_id' => '3', 'branch_id' => self::POINT]);

        self::assertSame(200, $seen['status']);
        $json = json_decode($seen['json'], true);
        self::assertSame(80, $json['price']);
        self::assertSame('2026-11-20', $json['estimatedDate']);
        self::assertCount(1, $this->transport->requests());
    }

    public function testInvalidBranchIdMakesNoCall(): void
    {
        foreach (['1; DROP', '465', '', 'AAAAAAAA-0000-4000-8000-00000000000Z'] as $id) {
            $seen = $this->quote(['delivery_id' => '3', 'branch_id' => $id]);

            self::assertSame(400, $seen['status'], $id);
        }
        self::assertSame([], $this->transport->requests());
        self::assertSame([], $_SESSION, 'the choice is not written');
    }

    public function testUnknownBranchMakesNoCall(): void
    {
        $seen = $this->quote(['delivery_id' => '3', 'branch_id' => 'aaaaaaaa-0000-4000-8000-000000000009']);

        self::assertSame(400, $seen['status']);
        self::assertSame([], $this->transport->requests());
        self::assertSame([], $_SESSION);
    }

    public function testSendingOnlyBranchMakesNoCall(): void
    {
        $seen = $this->quote(['delivery_id' => '3', 'branch_id' => self::SENDING_ONLY]);

        self::assertSame(400, $seen['status']);
        self::assertSame([], $this->transport->requests());
        self::assertSame([], $_SESSION);
    }

    public function testOrderIdIgnored(): void
    {
        $this->answer();
        $plain = $this->quote(['delivery_id' => '3', 'branch_id' => self::POINT])['json'];
        $_SESSION = [];
        $this->answer();

        $withOrder = $this->quote(['delivery_id' => '3', 'branch_id' => self::POINT, 'order_id' => '1'])['json'];

        self::assertSame($plain, $withOrder);
        foreach (new \ReflectionClass(CartController::class)->getMethods() as $method) {
            foreach ($method->getParameters() as $param) {
                self::assertStringStartsNotWith('Okay\\Admin\\', (string)$param->getType(), $method->getName());
            }
        }
    }

    public function testCrossSiteQuoteRefused(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $seen = $this->quote(['delivery_id' => '3', 'branch_id' => self::POINT]);

        self::assertSame(403, $seen['status']);
        self::assertSame([], $this->transport->requests());
        self::assertSame([], $_SESSION);
    }

    public function testCitiesAndBranchesNeverCallMeest(): void
    {
        $db = new class extends Database {
            public function __construct()
            {
            }

            public function __destruct()
            {
            }

            public function query(\Aura\SqlQuery\QueryInterface $query, $debug = false)
            {
                return true;
            }

            public function results($field = null, $mapped = null)
            {
                return [];
            }
        };
        $queryFactory = new class (new AuraQueryFactory('mysql')) extends QueryFactory {
            public function newSelect()
            {
                $select = new \ReflectionClass(Select::class)->newInstanceWithoutConstructor();
                new \ReflectionProperty(Select::class, 'queryObject')->setValue($select, new AuraQueryFactory('mysql')->newSelect());

                return $select;
            }
        };
        $controller = new CartController();

        $_GET = ['q' => 'вигадане'];
        [$response, $seen] = $this->response();
        $controller->cities(self::request(), $response, new CitySearch($db, $queryFactory), $this->quotes);
        self::assertSame(['cities' => []], json_decode($seen['json'], true));

        $_GET = ['city_id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'q' => '1'];
        [$response, $seen] = $this->response();
        $money = $this->createStub(Money::class);
        $controller->branches(self::request(), $response, new BranchList($db, $queryFactory, $this->groups, $this->createStub(Settings::class)), self::cart(), self::userHelper(), new UahTotal($this->entities, $money), $this->quotes);
        self::assertSame(200, $seen['status']);
        self::assertArrayHasKey('points', json_decode($seen['json'], true));

        self::assertSame([], $this->transport->requests());
    }

    public function testBranchesRejectsNonUuidCity(): void
    {
        $_GET = ['city_id' => '1 OR 1=1'];
        [$response, $seen] = $this->response();

        new CartController()->branches(self::request(), $response, new \ReflectionClass(BranchList::class)->newInstanceWithoutConstructor(), self::cart(), self::userHelper(), new UahTotal($this->entities, $this->createStub(Money::class)), $this->quotes);

        self::assertSame(400, $seen['status']);
    }

    public function testResponsesNeverContainContractIdOrToken(): void
    {
        $this->answer();
        $ok = $this->quote(['delivery_id' => '3', 'branch_id' => self::POINT])['json'];
        $_SESSION = [];
        $this->transport->json(400, ['status' => 'Error', 'info' => ['fieldName' => 'contractID', 'message' => 'bad contract ' . self::CONTRACT . ' token ' . self::TOKEN]]);
        $failed = $this->quote(['delivery_id' => '3', 'branch_id' => self::POINT])['json'];

        foreach ([$ok, $failed] as $json) {
            self::assertStringNotContainsStringIgnoringCase(self::CONTRACT, $json);
            self::assertStringNotContainsString(self::TOKEN, $json);
        }
    }
}
