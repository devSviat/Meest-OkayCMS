<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FakeTransport;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Backend\Controllers\ParcelAdmin;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelLookup;
use Okay\Modules\Sviat\Meest\Parcels\ParcelService;
use Okay\Modules\Sviat\Meest\Parcels\ParcelTracking;
use Okay\Modules\Sviat\Meest\Parcels\Stickers;
use PHPUnit\Framework\TestCase;

/**
 * Meest тут — FakeTransport без підготовлених відповідей: запит, якого тест не чекає, кидає виняток.
 * Залежності, яких дія не повинна торкатися, створено без конструкторів: дійти до них означає впасти.
 */
class ParcelAdminTest extends TestCase
{
    private const string TOKEN = 'cabinet-token-abc';
    private const string CONTRACT = 'dddddddd-0000-4000-8000-00000000000b';
    private const string PARCEL_ID = 'ffffffff-0000-4000-8000-000000000001';
    private const array ACTIONS = ['create', 'lookup', 'choose', 'attach', 'remove', 'unlink', 'refresh', 'sticker'];

    /** @var array{status: ?int, body: mixed, type: ?string, headers: list<string>} */
    private array $answer;
    private FakeTransport $transport;
    private InMemoryParcelRows $rows;
    private bool $active = true;

    protected function setUp(): void
    {
        $_POST = [];
        $_SESSION = [];
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryParcelRows();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        session_id('');
    }

    private function call(string $method, array $post): void
    {
        $this->answer = ['status' => null, 'body' => null, 'type' => null, 'headers' => []];
        $_POST = $post;
        $request = new class ($method) extends Request {
            public function __construct(private string $verb)
            {
            }

            public function method($method = null)
            {
                return $method === null ? $this->verb : strtolower((string)$method) === strtolower($this->verb);
            }
        };
        $answer = &$this->answer;
        $response = new class ($answer) extends Response {
            public function __construct(private array &$answer)
            {
            }

            public function setStatusCode($statusCode): Response
            {
                $this->answer['status'] = $statusCode;

                return $this;
            }

            public function addHeader($headerContent, $replace = true, $responseCode = null): Response
            {
                $this->answer['headers'][] = $headerContent;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                $this->answer['type'] = $type;
                $this->answer['body'] = $type === RESPONSE_JSON ? json_decode((string)$content, true) : $content;

                return $this;
            }
        };

        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('get')->willReturnCallback(static fn($id) => (int)$id === 7 ? (object)['id' => 7, 'delivery_id' => 6] : false);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($orders);

        $controller = new \ReflectionClass(ParcelAdmin::class)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);
        $parent->getProperty('entityFactory')->setValue($controller, $entityFactory);

        $credentials = new FixedCredentials(new Credentials(self::TOKEN, self::CONTRACT));
        $state = new InMemoryState();
        $now = static fn(): int => 1_800_000_000;
        $logger = new ArrayLogger();
        $client = new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $state, new AuthFlags($state), $now),
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            $logger,
        );
        $blank = static fn(string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $translations = $blank(BackendTranslations::class);
        $lang = [];
        include dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $translations->addTranslation($key, $text);
        }

        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(fn($vendor, $name): bool => $this->active && $vendor === 'Sviat' && $name === 'Meest');

        $controller->fetch(
            new ParcelService($client, $this->rows, $credentials, new ParcelIssues(), $logger, $now),
            $blank(ParcelDrafts::class),
            $blank(ParcelLookup::class),
            $blank(ParcelTracking::class),
            new Stickers($client, $this->rows),
            $this->rows,
            new ParcelIssues(),
            $translations,
            $blank(OrderDeliveryLines::class),
            $blank(Settings::class),
            $modules,
        );
    }

    private function parcel(): void
    {
        self::assertTrue($this->rows->attach(7, ['parcel_id' => self::PARCEL_ID, 'barcode' => '711-2550636', 'contract_id' => self::CONTRACT, 'status_group' => 'created']));
    }

    /** Форк звіряє токен із $_SESSION['id'], сток 4.5.3 — із session_id(): заповнюємо обидва. */
    private function signedIn(): string
    {
        $_SESSION['id'] = str_repeat('c', 64);
        session_id($_SESSION['id']);

        return $_SESSION['id'];
    }

    public function testGetWithActionWritesNothing(): void
    {
        $this->parcel();
        $before = $this->rows->rows;
        foreach (self::ACTIONS as $action) {
            $this->call('GET', ['action' => $action, 'order_id' => '7', 'barcode' => '711-2550637', 'parcel_id' => self::PARCEL_ID, 'session_id' => $this->signedIn()]);

            self::assertSame(405, $this->answer['status'], $action);
            self::assertSame(['ok' => false], $this->answer['body'], $action);
        }
        self::assertEquals($before, $this->rows->rows);
        self::assertSame([], $this->transport->requests());
    }

    public function testMissingSessionIs403AndNothingCreated(): void
    {
        $this->parcel();
        $before = $this->rows->rows;
        foreach (self::ACTIONS as $action) {
            $this->call('POST', ['action' => $action, 'order_id' => '7', 'barcode' => '711-2550637', 'weight' => '2']);
            self::assertSame(403, $this->answer['status'], $action);
            self::assertSame(['ok' => false], $this->answer['body'], $action);

            $this->call('POST', ['action' => $action, 'order_id' => '7', 'session_id' => str_repeat('a', 64)]);
            self::assertSame(403, $this->answer['status'], "{$action}: wrong token");
        }
        self::assertEquals($before, $this->rows->rows);
        self::assertSame([], $this->transport->requests());
    }

    /** Адмінка запускає init() і вимкнених модулів, тож контролер досяжний: вимкнений Meest нічого не пише й не питає Meest. */
    public function testDisabledModuleRefusesEveryAction(): void
    {
        $this->parcel();
        $before = $this->rows->rows;
        $this->active = false;
        foreach (self::ACTIONS as $action) {
            $this->call('POST', ['action' => $action, 'order_id' => '7', 'barcode' => '711-2550637', 'parcel_id' => self::PARCEL_ID, 'session_id' => $this->signedIn()]);

            self::assertSame(RESPONSE_JSON, $this->answer['type'], $action);
            self::assertFalse($this->answer['body']['ok'], $action);
            self::assertSame('Модуль Meest вимкнено: дії з посилками недоступні.', $this->answer['body']['issues'][0]['text'], $action);
        }
        self::assertEquals($before, $this->rows->rows);
        self::assertSame([], $this->transport->requests());
    }

    public function testUnknownOrderAndActionAreRefused(): void
    {
        $this->call('POST', ['action' => 'sticker', 'order_id' => '8', 'session_id' => $this->signedIn()]);
        self::assertSame(404, $this->answer['status']);

        $this->call('POST', ['action' => 'erase', 'order_id' => '7', 'session_id' => $this->signedIn()]);
        self::assertSame(400, $this->answer['status']);
        self::assertSame([], $this->transport->requests());
    }

    public function testStickerStreamsPdfAsAttachmentWithNosniff(): void
    {
        $this->parcel();
        $this->transport->push(new HttpResponse(200, '%PDF-1.4 sticker', 'application/pdf'));

        $this->call('POST', ['action' => 'sticker', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertSame(200, $this->answer['status']);
        self::assertSame('%PDF-1.4 sticker', $this->answer['body']);
        self::assertContains('Content-Type: application/pdf', $this->answer['headers']);
        self::assertContains('Content-Disposition: attachment; filename="meest-711-2550636.pdf"', $this->answer['headers']);
        self::assertContains('X-Content-Type-Options: nosniff', $this->answer['headers']);
        self::assertStringEndsWith('print/sticker100/' . self::PARCEL_ID, $this->transport->requests()[0]->url);
    }

    /** Meest може відповісти сторінкою HTML: її байти не потрапляють на домен адмінки ні файлом, ні текстом помилки. */
    public function testHtmlStickerAnswerBecomesJsonError(): void
    {
        $this->parcel();
        $this->transport->push(new HttpResponse(200, '<html><script>alert(document.cookie)</script></html>', 'text/html'));

        $this->call('POST', ['action' => 'sticker', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertSame(RESPONSE_JSON, $this->answer['type']);
        self::assertFalse($this->answer['body']['ok']);
        self::assertStringStartsWith('Meest відповіла не PDF-файлом', $this->answer['body']['issues'][0]['text']);
        self::assertStringNotContainsString('<script', json_encode($this->answer['body']));
        self::assertStringNotContainsString('script', json_encode($this->answer['body']));
        self::assertSame([], array_filter($this->answer['headers'], static fn(string $h): bool => str_starts_with($h, 'Content-Disposition')));
    }

    public function testStickerWithoutParcelIsJson(): void
    {
        $this->call('POST', ['action' => 'sticker', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertFalse($this->answer['body']['ok']);
        self::assertSame('Для цього замовлення немає посилки Meest, яку можна надрукувати.', $this->answer['body']['issues'][0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    public function testRemoveDeletesTheRow(): void
    {
        $this->parcel();
        $this->transport->json(200, ['status' => 'OK', 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => ''], 'result' => []]);

        $this->call('POST', ['action' => 'remove', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertTrue($this->answer['body']['ok']);
        self::assertNull($this->rows->forOrder(7));
        self::assertSame('DELETE', $this->transport->requests()[0]->method);
    }

    /** Текст Meest іде в JSON як є: JS вставляє його лише через textContent, а токен у відповідь не потрапляє. */
    public function testMeestRefusalTextReachesTheManagerWithoutTheToken(): void
    {
        $this->parcel();
        $this->transport->json(400, ['status' => 'Error', 'info' => ['fieldName' => '', 'message' => 'Parcel is accepted', 'messageDetails' => ''], 'result' => null]);

        $this->call('POST', ['action' => 'remove', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertFalse($this->answer['body']['ok']);
        self::assertSame('Meest не видалила посилку: найімовірніше, її вже передано на доставку. Оновіть статус.', $this->answer['body']['issues'][0]['text']);
        self::assertNotNull($this->rows->forOrder(7));
        self::assertStringNotContainsString(self::TOKEN, json_encode($this->answer['body']));
    }

    public function testChooseNeedsAParcelId(): void
    {
        $this->call('POST', ['action' => 'choose', 'order_id' => '7', 'parcel_id' => '../parcel', 'session_id' => $this->signedIn()]);

        self::assertFalse($this->answer['body']['ok']);
        self::assertSame([], $this->transport->requests());
    }
}
