<?php

namespace Modules\Sviat\Meest\Orders;

require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\Meest\Support\InMemoryParcelRows;
use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Modules;
use Okay\Core\Modules\Module;
use Okay\Modules\Sviat\DeliveryFilter\Helpers\DeliveryStatusGroups;
use Okay\Modules\Sviat\Meest\Extenders\BackendExtender;
use Okay\Modules\Sviat\Meest\Extenders\DeliveryFilterExtender;
use Okay\Modules\Sviat\Meest\Orders\DeliveryFilterHub;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;
use PHPUnit\Framework\TestCase;

/** Meest у списку замовлень — лише через хаб Sviat/DeliveryFilter: група `meest` і пошук штрихкодом. */
class DeliveryFilterHooksTest extends TestCase
{
    private const string HUB = 'Okay\\Modules\\Sviat\\DeliveryFilter\\Helpers\\DeliveryFilterHelper';
    private const int MODULE = 42;

    private InMemoryParcelRows $rows;

    protected function setUp(): void
    {
        $this->rows = new InMemoryParcelRows();
        self::assertTrue($this->rows->attach(77, ['barcode' => '711-2550636']));
        self::assertTrue($this->rows->attach(78, ['barcode' => 'CV123456789UA']));
        self::assertTrue($this->rows->attach(79, ['barcode' => '7113333333']));
    }

    private function hooks(string $label = 'ua', bool $active = true): DeliveryFilterExtender
    {
        $lang = [];
        include dirname(__DIR__, 5) . "/Okay/Modules/Sviat/Meest/Backend/lang/{$label}.php";
        $translations = $this->createStub(BackendTranslations::class);
        $translations->method('getTranslation')->willReturnCallback(static fn(string $key): string => $lang[$key] ?? '');
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);

        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn($vendor, $name): bool => $active && $vendor === 'Sviat' && $name === 'Meest');

        return new DeliveryFilterExtender($this->rows, $translations, $module, $modules);
    }

    public function testGroupDescribesEveryFilterGroup(): void
    {
        $filters = $this->hooks()->deliveryStatusFilters(['np' => []]);

        self::assertSame(['np', 'meest'], array_keys($filters));
        self::assertSame('Meest', $filters['meest']['title']);
        self::assertSame('Meest', $filters['meest']['short']);
        self::assertArrayNotHasKey('legacy_param', $filters['meest'], 'старих закладок Meest немає');
        self::assertSame(ParcelStatus::FILTER_GROUPS, array_keys($filters['meest']['options']));
        self::assertSame('У відділенні', $filters['meest']['options']['arrived']);
        self::assertSame('Без посилки', $filters['meest']['options']['none']);
    }

    public function testGroupPassesTheHubWhitelist(): void
    {
        if (!class_exists(DeliveryStatusGroups::class)) {
            self::markTestSkipped('хаба Sviat/DeliveryFilter тут немає');
        }
        foreach (['ua', 'en', 'ru'] as $label) {
            self::assertSame(['meest'], array_keys(DeliveryStatusGroups::sanitize($this->hooks($label)->deliveryStatusFilters([]))), $label);
        }
    }

    public function testStatusBecomesEntityFilter(): void
    {
        self::assertSame(['status_id' => 2, 'meest_status_groups' => ['arrived']], $this->hooks()->filterByDeliveryStatus(['status_id' => 2], [], 'meest', 'arrived'));
        self::assertSame(['meest_without_parcel' => self::MODULE], $this->hooks()->filterByDeliveryStatus([], [], 'meest', 'none'));
    }

    public function testOtherCarrierAndUnknownStatusAreLeftAlone(): void
    {
        self::assertSame(['up_status_groups' => ['arrived']], $this->hooks()->filterByDeliveryStatus(['up_status_groups' => ['arrived']], [], 'up', 'arrived'));
        self::assertSame([], $this->hooks()->filterByDeliveryStatus([], [], 'meest', "arrived' OR 1=1"));
    }

    /** Штрихкод із SMS: з пробілами, малими літерами, з дефісом чи без — як би його не збережено. */
    public function testBarcodeKeywordBecomesIdFilter(): void
    {
        self::assertSame([3, 77], $this->hooks()->ordersByShipmentNumber([3], ' 711 2550636 '));
        self::assertSame([77], $this->hooks()->ordersByShipmentNumber([], '7112550636'));
        self::assertSame([77], $this->hooks()->ordersByShipmentNumber([], '711-2550636'));
        self::assertSame([79], $this->hooks()->ordersByShipmentNumber([], '711-3333333'));
        self::assertSame([78], $this->hooks()->ordersByShipmentNumber([], 'cv123456789ua'));
    }

    public function testAnythingElseFindsNothing(): void
    {
        foreach (['Олена', 'Shevchenko', '7119999999', '77', ''] as $keyword) {
            self::assertSame([5], $this->hooks()->ordersByShipmentNumber([5], $keyword), $keyword);
        }
    }

    /** Свого фільтра й лічильників модуль не має: на buildFilter лише масова дія. */
    public function testNoOwnFilterOrCounts(): void
    {
        self::assertFalse(method_exists(BackendExtender::class, 'countFilter'));
        $init = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Init/Init.php');
        self::assertStringNotContainsString('buildCountStatusesFilter', $init);
        self::assertStringContainsString('DeliveryFilterHub::hooks(DeliveryFilterHub::HELPER)', $init);
    }

    public function testInitDoesNotRegisterHubHooksWithoutClass(): void
    {
        self::assertSame([], DeliveryFilterHub::hooks(self::HUB . 'Missing'));
        self::assertSame(self::HUB, DeliveryFilterHub::HELPER);
        self::assertSame([
            [[MeestHubWithAllMethods::class, 'findDeliveryStatusFilters'], [DeliveryFilterExtender::class, 'deliveryStatusFilters']],
            [[MeestHubWithAllMethods::class, 'filterByDeliveryStatus'], [DeliveryFilterExtender::class, 'filterByDeliveryStatus']],
            [[MeestHubWithAllMethods::class, 'findOrderIdsByShipmentNumber'], [DeliveryFilterExtender::class, 'ordersByShipmentNumber']],
        ], DeliveryFilterHub::hooks(MeestHubWithAllMethods::class));
    }

    /** Select, приховане поле й підпис під пошуком дає хаб; module.json чіпає orders.tpl лише для масових дій. */
    public function testNoOwnFilterTemplates(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest';
        $json = (string)file_get_contents($root . '/Init/module.json');
        foreach (['orders_status_filter.tpl', 'orders_search.tpl', 'orders_search_hint.tpl'] as $file) {
            self::assertStringNotContainsString('"' . $file . '"', $json);
            self::assertFileDoesNotExist($root . '/Backend/design/html/' . $file);
        }
        foreach (['{if $labels}', '<form class=\\"search\\" method=\\"get\\">', 'input-group--search'] as $anchor) {
            self::assertStringNotContainsString($anchor, $json);
        }
        $lang = [];
        include $root . '/Backend/lang/ua.php';
        self::assertSame([], array_values(array_filter(array_keys($lang), static fn(string $k): bool => str_contains($k, 'list_filter') || str_contains($k, 'list_search_hint'))));
    }

    /** Адмінка запускає init() і вимкнених модулів: ланки вимкненого перевізника зареєстровані, але мусять мовчати. */
    public function testDisabledCarrierAddsNothing(): void
    {
        $hooks = $this->hooks('ua', false);

        self::assertSame(['xx' => []], $hooks->deliveryStatusFilters(['xx' => []]));
        self::assertSame(['page' => 1], $hooks->filterByDeliveryStatus(['page' => 1], ['page' => 1], 'meest', 'arrived'));
        self::assertSame([5], $hooks->ordersByShipmentNumber([5], ' 711 2550636 '));
    }

    /** Новіший перевізник поруч зі старішим хабом, де ще немає якоїсь ланки: реєстрація кинула б виняток в init(). */
    public function testNoHooksWhenTheHubLacksAMethod(): void
    {
        self::assertSame([], DeliveryFilterHub::hooks(MeestHubWithoutSearch::class));
    }
}

class MeestHubWithAllMethods
{
    public function findDeliveryStatusFilters(): array
    {
        return [];
    }

    public function filterByDeliveryStatus(array $filter, string $carrier, string $status): array
    {
        return $filter;
    }

    public function findOrderIdsByShipmentNumber(string $keyword): array
    {
        return [];
    }
}

class MeestHubWithoutSearch
{
    public function findDeliveryStatusFilters(): array
    {
        return [];
    }

    public function filterByDeliveryStatus(array $filter, string $carrier, string $status): array
    {
        return $filter;
    }
}
