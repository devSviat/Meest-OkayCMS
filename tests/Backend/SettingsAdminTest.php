<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/ContractFixtures.php';
require_once __DIR__ . '/../Support/SettingsPage.php';
require_once __DIR__ . '/../Support/InMemoryDictionaryStore.php';
require_once __DIR__ . '/../Support/FakeDictionaryEntities.php';
require_once __DIR__ . '/SenderBranchesTest.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\ContractFixtures;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FakeDictionaryEntities;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryDictionaryStore;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Modules\Sviat\Meest\Support\SettingsPage;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Backend\Controllers\MeestSettingsAdmin;
use Okay\Modules\Sviat\Meest\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Meest\Backend\KeySettings;
use Okay\Modules\Sviat\Meest\Backend\KeySettingsView;
use Okay\Modules\Sviat\Meest\Backend\SenderBranches;
use Okay\Modules\Sviat\Meest\Backend\SenderSettings;
use Okay\Modules\Sviat\Meest\Backend\ShippingSettings;
use Okay\Modules\Sviat\Meest\Cart\PopularCities;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Meest\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Entities\GroupEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Налаштування й перевірка йдуть через справжні класи модуля поверх сховищ у пам'яті й фейкового
 * транспорту; сторінку рендерить справжній Smarty зі змінними, які призначив контролер.
 */
class SettingsAdminTest extends TestCase
{
    use FakeDictionaryEntities;

    private const string TOKEN = 'cabinet-token-zq7';
    private const string CONTRACT = '0a1b2c3d-0000-4000-8000-00000000000a';
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string CITY_A = '11111111-1111-4111-8111-111111111111';

    /** @var list<array<string, mixed>> */
    private array $groupWrites = [];
    private ?int $status = null;
    private string $content = '';
    /** @var array<string, mixed> */
    private array $writes = [];
    /** @var array<string, mixed> */
    private array $assigned = [];
    private InMemoryState $state;
    private FakeTransport $http;

    protected function setUp(): void
    {
        $_POST = [];
        $_GET = [];
        // Форк звіряє токен із $_SESSION['id'], сток 4.5.3 — із session_id(): заповнюємо обидва.
        $_SESSION = ['id' => self::SESSION];
        session_id(self::SESSION);
        $this->status = null;
        $this->content = '';
        $this->assigned = [];
        $this->groupWrites = [];
        $this->writes = [Credentials::CABINET_TOKEN => self::TOKEN, Credentials::CONTRACT_ID => self::CONTRACT];
        $this->state = new InMemoryState();
        $this->http = new FakeTransport();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        session_id('');
    }

    /** @param array<string, mixed> $post */
    private function visit(string $method, array $post = [], string $action = 'fetch'): void
    {
        $_POST = $post;
        $this->assigned = [];
        $request = new class ($method) extends Request {
            public function __construct(private string $verb)
            {
            }

            public function method($method = null)
            {
                return $method === null ? $this->verb : strtolower((string)$method) === strtolower($this->verb);
            }
        };
        $status = &$this->status;
        $content = &$this->content;
        $response = new class ($status, $content) extends Response {
            public function __construct(private ?int &$status, private string &$content)
            {
            }

            public function setStatusCode($statusCode): Response
            {
                $this->status = $statusCode;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                $this->content = (string)$content;

                return $this;
            }
        };
        $assigned = &$this->assigned;
        $design = new class ($assigned) extends Design {
            public function __construct(private array &$assigned)
            {
            }

            public function assign($var, $value, $dynamicJs = false)
            {
                $this->assigned[$var] = $value;
            }

            public function fetch($template, $forceMinify = false)
            {
                return SettingsPage::render($this->assigned);
            }
        };

        $controller = new \ReflectionClass(MeestSettingsAdmin::class)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);
        $parent->getProperty('design')->setValue($controller, $design);

        $writes = &$this->writes;
        $settings = new class ($writes) extends Settings {
            public function __construct(private array &$writes)
            {
            }

            public function get($param)
            {
                return $this->writes[$param] ?? null;
            }

            public function set($param, $value)
            {
                $this->writes[$param] = $value;
            }
        };
        $provider = new class ($settings) extends \Okay\Modules\Sviat\Meest\Api\CredentialsProvider {
            public function __construct(private Settings $settings)
            {
            }

            public function get(): Credentials
            {
                return Credentials::fromSettings($this->settings->get(...));
            }
        };
        $now = static fn(): int => 1_800_000_000;
        $flags = new AuthFlags($this->state);
        $tokens = new TokenProvider($provider, $this->state, $flags, $now);
        $client = new MeestClient($this->http, $tokens, new RequestBudget($this->state, new InMemoryMutex(), $now), $provider, new ArrayLogger());
        $checker = new KeyChecker($client, $tokens, $provider, $this->state, $flags, $now);
        $cities = [['id' => SenderBranchesTest::CITY, 'name_ua' => 'Вигадане', 'type_ua' => 'м.', 'region_ua' => 'Вигадана', 'search_text' => 'вигадане']];
        $branches = new SenderBranches($this->dictionaryFactory($cities, SenderBranchesTest::branches()));
        $counts = $this->createStub(DictionaryCounts::class);
        $counts->method('byKind')->willReturn(['branch' => 2, 'postomat' => 1]);
        $counts->method('cities')->willReturn(1);
        $counts->method('regions')->willReturn(1);
        $real = [
            KeySettings::class => new KeySettings($settings, $tokens, $this->state, $flags, $checker),
            KeySettingsView::class => new KeySettingsView($provider, $checker, $flags),
            SenderSettings::class => new SenderSettings($settings, $branches),
            SenderBranches::class => $branches,
            ShippingSettings::class => new ShippingSettings($settings),
            DictionaryStatusView::class => new DictionaryStatusView($this->state, $counts, $now),
            DictionaryRunner::class => new DictionaryRunner(new RegionSweep($client, $this->state), new InMemoryDictionaryStore(), $this->state, new InMemoryMutex(), $provider, $checker, $now),
            KeyChecker::class => $checker,
            GroupsStore::class => new GroupsStore($this->groupsFactory()),
            PopularCities::class => new PopularCities($this->state, $this->citiesFactory()),
            DictionaryCounts::class => $counts,
        ];

        $arguments = [];
        foreach (new \ReflectionMethod($controller, $action)->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            $arguments[] = $real[$type->getName()] ?? self::fail('unexpected dependency ' . $type->getName());
        }
        $controller->$action(...$arguments);
    }

    private function groupsFactory(): EntityFactory
    {
        $groups = $this->createStub(GroupEntity::class);
        $groups->method('find')->willReturn([]);
        $groups->method('add')->willReturnCallback(function (array $row): int {
            $this->groupWrites[] = $row;

            return count($this->groupWrites);
        });
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($groups);

        return $factory;
    }

    private function citiesFactory(): EntityFactory
    {
        $known = [self::CITY_A => (object)['id' => self::CITY_A, 'name_ua' => 'Вигадане', 'type_ua' => 'м.', 'region_ua' => 'Вигадана']];
        $cities = $this->createStub(CityEntity::class);
        $cities->method('cols')->willReturnSelf();
        $cities->method('noLimit')->willReturnSelf();
        $cities->method('find')->willReturnCallback(static fn(array $filter = []) => array_values(array_intersect_key($known, array_flip($filter['id'] ?? []))));
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($cities);

        return $factory;
    }

    /** @return array<string, mixed> */
    private function settingsSnapshot(): array
    {
        return $this->writes;
    }

    public function testPageNeverContainsSecrets(): void
    {
        $this->http->json(200, ContractFixtures::body());
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'check']);
        $withResult = $this->content;
        $this->visit('GET');

        foreach ([$withResult, $this->content] as $html) {
            self::assertStringNotContainsString(self::TOKEN, $html);
            self::assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="cabinet_token"[^>]*value=""/', $html);
            self::assertStringNotContainsString(self::TOKEN, (string)json_encode($this->assigned));
        }
    }

    public function testGetWithActionWritesNothing(): void
    {
        $before = $this->settingsSnapshot();
        $_GET = ['meest_action' => 'access', 'cabinet_token' => 'attacker-token'];

        $this->visit('GET', ['meest_action' => 'check', 'cabinet_token' => 'attacker-token', 'session_id' => self::SESSION]);

        self::assertSame($before, $this->writes);
        self::assertSame([], $this->state->values, 'module state unchanged');
        self::assertSame([], $this->http->requests());
    }

    public function testPostWithoutSessionTokenIs403AndChangesNothing(): void
    {
        $before = $this->settingsSnapshot();

        $this->visit('POST', ['meest_action' => 'access', 'cabinet_token' => 'attacker-token', 'clear_cabinet_token' => '1']);

        self::assertSame(403, $this->status);
        self::assertSame($before, $this->writes);
        self::assertSame([], $this->state->values);
        self::assertSame([], $this->http->requests());
    }

    public function testWrongSessionTokenIs403(): void
    {
        $before = $this->settingsSnapshot();

        $this->visit('POST', ['meest_action' => 'check', 'session_id' => str_repeat('b', 64)]);

        self::assertSame(403, $this->status);
        self::assertSame($before, $this->writes);
        self::assertSame([], $this->http->requests());
    }

    public function testGetDoesNotCallMeest(): void
    {
        $this->visit('GET');

        self::assertSame([], $this->http->requests());
        self::assertSame('', (string)$this->status);
        self::assertStringContainsString('id="meest_access_form"', $this->content);
    }

    public function testAccessActionChecksNewTokenOnceBeforeSaving(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'access', 'cabinet_token' => 'brand-new-token', 'contract_id' => self::CONTRACT]);

        self::assertSame('brand-new-token', $this->writes[Credentials::CABINET_TOKEN]);
        self::assertCount(1, $this->http->requests());
        self::assertTrue($this->assigned['meest_saved'] ?? false);
        self::assertStringNotContainsString('brand-new-token', $this->content);
    }

    public function testCheckWithNewTokenSendsOneRequest(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'check', 'cabinet_token' => 'brand-new-token']);

        self::assertCount(1, $this->http->requests(), 'the save already checked it');
        self::assertSame('brand-new-token', $this->writes[Credentials::CABINET_TOKEN]);
    }

    public function testRejectedTokenKeepsPreviousAndSaysSo(): void
    {
        $this->http->json(401, ['info' => ['message' => 'bad token']]);

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'access', 'cabinet_token' => 'mistyped-token']);

        self::assertSame(self::TOKEN, $this->writes[Credentials::CABINET_TOKEN]);
        self::assertSame(['cabinet_token' => 'sviat__meest__err_token_rejected'], $this->assigned['meest_errors']);
        self::assertStringContainsString('лишився попередній', $this->content);
        self::assertStringNotContainsString('mistyped-token', $this->content);
    }

    public function testSavingWithoutNewTokenDoesNotCallMeest(): void
    {
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'access', 'cabinet_token' => '', 'contract_id' => self::CONTRACT]);

        self::assertSame([], $this->http->requests());
        self::assertTrue($this->assigned['meest_saved'] ?? false);
    }

    public function testInvalidContractIsShownAndNothingSaved(): void
    {
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'access', 'cabinet_token' => 'brand-new-token', 'contract_id' => 'bad']);

        self::assertSame(self::TOKEN, $this->writes[Credentials::CABINET_TOKEN]);
        self::assertSame(['contract_id' => 'sviat__meest__err_contract_id'], $this->assigned['meest_errors']);
        self::assertStringContainsString('contractID', $this->content);
        self::assertStringNotContainsString('sviat__meest__', $this->content, 'every translation key exists');
    }

    public function testCheckShowsMeestTextOnlyInResult(): void
    {
        $this->http->json(400, ['info' => ['message' => 'Договір <b>не знайдено</b>']]);

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'check']);

        self::assertCount(1, $this->http->requests());
        self::assertStringContainsString('Договір &lt;b&gt;не знайдено&lt;/b&gt;', $this->content, 'shown escaped in the result');
        self::assertStringNotContainsString('<b>не знайдено</b>', $this->content);

        $this->visit('GET');

        self::assertStringNotContainsString('не знайдено', $this->content, 'a later visit does not repeat it');
        self::assertStringNotContainsString('не знайдено', implode('', $this->state->values));
    }

    public function testCheckPersistsResultForLaterVisits(): void
    {
        $this->http->json(200, ContractFixtures::body());

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'check']);
        $this->visit('GET');

        self::assertStringContainsString('ТОВ Тестовий Відправник', $this->content);
        self::assertStringContainsString('00000001', $this->content);
        self::assertCount(1, $this->http->requests());
    }

    public function testUnknownActionDoesNothing(): void
    {
        $before = $this->settingsSnapshot();

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'no_such_action', 'cabinet_token' => 'attacker-token']);

        self::assertSame($before, $this->writes);
        self::assertSame([], $this->http->requests());
    }

    public function testBannerShowsTokenFlag(): void
    {
        $this->visit('GET');
        self::assertStringNotContainsString('id="meest_auth_notice"', $this->content);

        new AuthFlags($this->state)->record(AuthFlags::TOKEN, MeestApiException::of(ApiErrorKind::BadToken, 'x'));
        $this->visit('GET');

        self::assertStringContainsString('id="meest_auth_notice"', $this->content);
        self::assertStringContainsString('Meest не приймає token', $this->content);
        self::assertStringNotContainsString('sviat__meest__', $this->content);
    }

    private const string BRANCH = '0a1b2c3d-0000-4000-8000-0000000000b1';

    public function testDictionaryButtonOnlyQueues(): void
    {
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'dictionaries_run']);

        self::assertSame([], $this->http->requests(), 'the button never calls Meest');
        self::assertArrayHasKey('dictionary_request', $this->state->values, 'a request is queued for the scheduler');
        self::assertArrayNotHasKey('dictionary_status', $this->state->values, 'the run state belongs to the runner');
        self::assertSame('queued', $this->assigned['meest_dict_requested']);
        self::assertTrue($this->assigned['meest_dict']['queued']);
        self::assertFalse($this->assigned['meest_dict']['can_run']);
    }

    public function testDictionaryButtonWithoutTokenQueuesNothing(): void
    {
        $this->writes[Credentials::CABINET_TOKEN] = '';

        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'dictionaries_run']);

        self::assertSame('no_token', $this->assigned['meest_dict_requested']);
        self::assertSame([], $this->state->values);
        self::assertSame([], $this->http->requests());
    }

    public function testGetNeverQueuesDictionaries(): void
    {
        $this->visit('GET', ['meest_action' => 'dictionaries_run', 'session_id' => self::SESSION]);

        self::assertSame([], $this->state->values);
        self::assertStringContainsString('id="meest_dict_form"', $this->content);
    }

    public function testSenderSavesOnlyValidInput(): void
    {
        $post = ['session_id' => self::SESSION, 'meest_action' => 'sender', 'sender_name' => 'ТОВ Тестовий', 'sender_phone' => '0671234567',
            'sender_city_id' => SenderBranchesTest::CITY, 'sender_branch_id' => self::BRANCH];

        $this->visit('POST', $post);

        self::assertSame('+380671234567', $this->writes[ShippingConfig::SENDER_PHONE]);
        self::assertTrue($this->assigned['meest_sender_saved']);
        self::assertSame([], $this->http->requests());

        $bad = $this->writes;
        $this->visit('POST', ['sender_branch_id' => '0a1b2c3d-0000-4000-8000-0000000000b2', 'sender_phone' => 'x'] + $post);

        self::assertSame($bad, $this->writes, 'a refused form changes nothing');
        self::assertArrayHasKey('sender_branch_id', $this->assigned['meest_sender_errors']);
        self::assertSame('x', $this->assigned['meest_sender']['values']['phone'], 'the form keeps what was typed');
        self::assertStringNotContainsString('sviat__meest__', $this->content);
    }

    public function testParcelDefaultsRefuseWhatContractForbids(): void
    {
        $this->http->json(200, ContractFixtures::body(['isAvailableCash' => true, 'isAvailableNoncash' => false]));
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'check']);
        $post = ['session_id' => self::SESSION, 'meest_action' => 'parcel_defaults', 'default_weight' => '2', 'box_length' => '10',
            'box_width' => '10', 'box_height' => '10', 'declared_value' => '0', 'pay_type' => 'noncash'];

        $this->visit('POST', $post);

        self::assertSame(['pay_type' => 'sviat__meest__shipping_err_pay_type_forbidden'], $this->assigned['meest_parcel_errors']);
        self::assertArrayNotHasKey(ShippingConfig::PAY_TYPE, $this->writes);
        self::assertTrue($this->assigned['meest_parcel']['forbidden']['noncash']);
        self::assertMatchesRegularExpression('/value="noncash"[^>]*disabled/', $this->content);

        $this->visit('POST', ['pay_type' => 'cash'] + $post);

        self::assertSame('cash', $this->writes[ShippingConfig::PAY_TYPE]);
        self::assertSame(2.0, $this->writes[ShippingConfig::DEFAULT_WEIGHT]);
    }

    public function testSenderBranchesEndpointOffersSendableOnly(): void
    {
        $_GET = ['city_id' => strtoupper(SenderBranchesTest::CITY)];

        $this->visit('GET', [], 'senderBranches');

        $ids = array_column(json_decode($this->content, true)['branches'], 'id');
        self::assertSame([self::BRANCH, '0a1b2c3d-0000-4000-8000-0000000000b3'], $ids);
        self::assertSame([], $this->http->requests());
    }

    public function testSenderCitiesEndpointNeedsTwoCharacters(): void
    {
        $_GET = ['q' => 'в'];
        $this->visit('GET', [], 'senderCities');
        self::assertSame([], json_decode($this->content, true)['cities']);

        $_GET = ['q' => 'Вигад'];
        $this->visit('GET', [], 'senderCities');
        self::assertSame('Вигадане', json_decode($this->content, true)['cities'][0]['name']);
    }

    public function testPopularSaveKeepsOnlyKnownCitiesInOrder(): void
    {
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'popular', 'popular' => ['not-a-city', self::CITY_A, strtoupper(self::CITY_A)]]);

        self::assertSame([self::CITY_A], json_decode((string)$this->state->get('popular_cities'), true));
        self::assertTrue($this->assigned['meest_popular_saved']);
        self::assertSame([self::CITY_A], array_column($this->assigned['meest_popular'], 'id'));
    }

    public function testGroupsSaveNeedsSessionAndValidForm(): void
    {
        $form = ['groups' => [['id' => 'new', 'name' => 'Усі пункти', 'enabled' => '1']], 'kinds' => ['branch' => 'new', 'postomat' => 'new']];

        $this->visit('POST', ['meest_action' => 'groups'] + $form);
        self::assertSame(403, $this->status);
        self::assertSame([], $this->groupWrites);

        $this->status = null;
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'groups'] + $form);
        self::assertSame('Усі пункти', $this->groupWrites[0]['name'] ?? null);
        self::assertSame('["branch","postomat"]', $this->groupWrites[0]['kinds'] ?? null);
        self::assertTrue($this->assigned['meest_groups_saved']);

        $this->groupWrites = [];
        $this->visit('POST', ['session_id' => self::SESSION, 'meest_action' => 'groups', 'groups' => []]);
        self::assertSame([], $this->groupWrites);
        self::assertSame(['sviat__meest__groups_error_none'], $this->assigned['meest_groups_errors']);
    }
}
