<?php

namespace Modules\Sviat\Meest\Cart;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Cart\CartSelection;
use Okay\Modules\Sviat\Meest\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Meest\Cart\DictionaryReady;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Entities\GroupEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class CartSelectionValidatorTest extends TestCase
{
    private const int MODULE = 81;
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string OTHER_CITY = 'bbbbbbbb-0000-4000-8000-000000000002';
    private const string BRANCH = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string TODAY = '2026-11-17';

    private static function delivery(array $override = []): object
    {
        return (object)($override + ['id' => 6, 'module_id' => self::MODULE]);
    }

    private static function city(string $id = self::CITY): object
    {
        return (object)['id' => $id, 'name_ua' => 'Вигадане', 'type_ua' => 'м.', 'district_ua' => 'Перший', 'region_ua' => 'Тестова'];
    }

    private static function branch(array $override = []): object
    {
        return (object)($override + [
            'id' => self::BRANCH, 'city_id' => self::CITY, 'number' => '1', 'kind' => 'branch', 'name_ua' => 'Відділення №1', 'address_ua' => 'вул. Лісова 1',
            'weight_place_max' => '30.00', 'weight_total_max' => null, 'insurance_max' => '15000.00', 'length_max' => 60, 'width_max' => 40, 'height_max' => 40,
            'inbound_from' => null, 'inbound_to' => null, 'sending_only' => 0, 'phone_required' => 0, 'overloaded' => 0,
        ]);
    }

    private static function groupRows(bool $branchesEnabled = true): array
    {
        return [
            (object)['id' => 1, 'enabled' => (int)$branchesEnabled, 'kinds' => '["branch"]', 'name' => 'Відділення'],
            (object)['id' => 2, 'enabled' => 1, 'kinds' => '["postomat"]', 'name' => 'Поштомати'],
        ];
    }

    private static function box(): ShippingConfig
    {
        return ShippingConfig::fromSettings(static fn(string $k): mixed => null);
    }

    private static function check(?object $delivery, ?object $city, ?object $branch, ?CartMetrics $cart = null, ?array $groups = null, bool $ready = true, float $declared = 1000.0, string $phone = '+380671234567'): CartSelection|string
    {
        return CartSelectionValidator::check($delivery, self::MODULE, $city, $branch, $cart ?? new CartMetrics(2.0, false, 1000.0, 1000.0), self::box(), $declared, self::TODAY, $groups ?? self::groupRows(), $ready, $phone);
    }

    public function testValidChoice(): void
    {
        $selection = self::check(self::delivery(), self::city(), self::branch());

        self::assertInstanceOf(CartSelection::class, $selection);
        self::assertTrue($selection->applies());
        self::assertSame(self::BRANCH, $selection->branch->id);
    }

    public function testForeignDeliveryIgnored(): void
    {
        self::assertEquals(CartSelection::none(), self::check(self::delivery(['module_id' => 12]), self::city(), self::branch()));
        self::assertFalse(self::check(null, null, null)->applies());
        self::assertFalse(CartSelectionValidator::check(self::delivery(['module_id' => 0]), 0, null, null, new CartMetrics(1.0, false, 1.0), self::box(), 1.0, self::TODAY, [])->applies(), 'module not resolved');
    }

    public function testUnknownCityOrBranchRejected(): void
    {
        self::assertSame('sviat__meest__err_city', self::check(self::delivery(), null, self::branch()));
        self::assertSame('sviat__meest__err_branch', self::check(self::delivery(), self::city(), null));
        self::assertSame('sviat__meest__err_branch', self::check(self::delivery(), self::city(), self::branch(['kind' => 'mobile'])), 'a row of unknown kind is not a pick-up point');
    }

    public function testBranchFromAnotherCityRejected(): void
    {
        self::assertSame('sviat__meest__err_branch_city', self::check(self::delivery(), self::city(self::OTHER_CITY), self::branch()));
    }

    public function testSendingOnlyRejected(): void
    {
        self::assertSame('sviat__meest__err_branch_hidden', self::check(self::delivery(), self::city(), self::branch(['sending_only' => 1])));
    }

    public function testDisabledGroupRejected(): void
    {
        self::assertSame('sviat__meest__err_branch_hidden', self::check(self::delivery(), self::city(), self::branch(), groups: self::groupRows(false)));
    }

    public function testTooHeavyRejected(): void
    {
        self::assertSame('sviat__meest__err_too_heavy', self::check(self::delivery(), self::city(), self::branch(), new CartMetrics(31.0, false, 1.0, 1.0)));
    }

    public function testEveryFitProblemRejects(): void
    {
        self::assertSame('sviat__meest__err_too_big', self::check(self::delivery(), self::city(), self::branch(['length_max' => 10])));
        self::assertSame('sviat__meest__err_insurance', self::check(self::delivery(), self::city(), self::branch(), declared: 20000.0));
        self::assertSame('sviat__meest__err_inbound', self::check(self::delivery(), self::city(), self::branch(['inbound_from' => '2026-11-10 00:00:00', 'inbound_to' => '2026-11-20 00:00:00'])));
    }

    public function testPhoneRequiredPointNeedsMobile(): void
    {
        $point = self::branch(['phone_required' => 1]);

        self::assertSame('sviat__meest__err_phone_mobile', self::check(self::delivery(), self::city(), $point, phone: '044 123 45 67'));
        self::assertSame('sviat__meest__err_phone_mobile', self::check(self::delivery(), self::city(), $point, phone: ''));
        self::assertInstanceOf(CartSelection::class, self::check(self::delivery(), self::city(), $point, phone: '067 123 45 67'));
        self::assertInstanceOf(CartSelection::class, self::check(self::delivery(), self::city(), self::branch(), phone: ''), 'other points take any phone');
    }

    public function testDictionaryNotReadyRejected(): void
    {
        self::assertSame('sviat__meest__err_dictionary', self::check(self::delivery(), null, null, ready: false));
        self::assertFalse(self::check(self::delivery(['module_id' => 12]), null, null, ready: false)->applies());
    }

    public function testPostedIdsOnlyForOwnDelivery(): void
    {
        $post = [
            'meest_city_id' => [6 => strtoupper(self::CITY), 7 => self::OTHER_CITY],
            'meest_branch_id' => [6 => self::BRANCH, 7 => '465'],
        ];

        self::assertSame([self::CITY, self::BRANCH], CartSelectionValidator::postedIds($post, 6));
        self::assertSame([self::OTHER_CITY, ''], CartSelectionValidator::postedIds($post, 7), 'a non-UUID is no id');
        self::assertSame(['', ''], CartSelectionValidator::postedIds($post, 8));
        self::assertSame(['', ''], CartSelectionValidator::postedIds(['meest_city_id' => 'x', 'meest_branch_id' => [6 => ['a']]], 6));
    }

    /** Довідник оновився між показом пункту й оформленням: пункту більше немає. */
    public function testBranchRemovedSinceShownRejected(): void
    {
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturn(self::delivery());
        $cities = $this->createStub(CityEntity::class);
        $cities->method('get')->willReturn(self::city());
        $branches = $this->createStub(BranchEntity::class);
        $branches->method('get')->willReturn(false);
        $groupEntity = $this->createStub(GroupEntity::class);
        $groupEntity->method('find')->willReturn(self::groupRows());
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class): object => match ($class) {
            DeliveriesEntity::class => $deliveries,
            CityEntity::class => $cities,
            BranchEntity::class => $branches,
            GroupEntity::class => $groupEntity,
        });
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);
        $state = new InMemoryState();
        $state->set('dictionary_status', json_encode(['lastRun' => ['status' => 'ok']]));
        $validator = new CartSelectionValidator($factory, $module, new GroupsStore($factory), new DictionaryReady($state, $factory), $this->createStub(Settings::class), static fn(): int => 1_800_000_000);

        $result = $validator->validate(
            (object)['delivery_id' => 6, 'phone' => '0671234567'],
            ['meest_city_id' => [6 => self::CITY], 'meest_branch_id' => [6 => self::BRANCH]],
            new CartMetrics(1.0, false, 100.0, 100.0),
        );

        self::assertSame('sviat__meest__err_branch', $result);
    }
}
