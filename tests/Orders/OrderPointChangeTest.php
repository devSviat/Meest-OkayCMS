<?php

namespace Modules\Sviat\Meest\Orders;

use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Orders\OrderPointChange;
use Okay\Modules\Sviat\Meest\Orders\OrderPriceRule;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class OrderPointChangeTest extends TestCase
{
    private const int MODULE = 81;
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string TODAY = '2026-11-17';

    private static function delivery(): object
    {
        return (object)['id' => 6, 'module_id' => self::MODULE, 'free_from' => 1_000_000];
    }

    private static function groupRows(bool $postomatsEnabled = true): array
    {
        return [
            (object)['id' => 1, 'name' => 'Відділення', 'enabled' => 1, 'position' => 1, 'kinds' => '["branch"]'],
            (object)['id' => 2, 'name' => 'Поштомати', 'enabled' => (int)$postomatsEnabled, 'position' => 2, 'kinds' => '["postomat"]'],
        ];
    }

    private static function city(string $id = self::CITY): object
    {
        return (object)['id' => $id, 'name_ua' => 'Вигадане', 'type_ua' => 'м.', 'district_ua' => 'Перший', 'region_ua' => 'Тестова'];
    }

    private static function branch(array $override = []): object
    {
        return (object)($override + [
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'city_id' => self::CITY, 'number' => '1', 'kind' => 'branch', 'name_ua' => 'Відділення №1', 'address_ua' => 'вул. Лісова 1',
            'weight_place_max' => '30.00', 'weight_total_max' => null, 'insurance_max' => null, 'length_max' => null, 'width_max' => null, 'height_max' => null,
            'inbound_from' => null, 'inbound_to' => null, 'sending_only' => 0, 'phone_required' => 0,
        ]);
    }

    private static function select(?object $branch, float $weight = 2.0, bool $postomatsEnabled = true, ?object $delivery = null, ?object $city = null, ?object $parcel = null): array
    {
        return OrderPointChange::select(
            $delivery ?? self::delivery(),
            self::MODULE,
            $city ?? self::city(),
            $branch,
            self::groupRows($postomatsEnabled),
            new CartMetrics($weight, false, 1000.0, 1000.0),
            ShippingConfig::fromSettings(static fn(string $k): mixed => null),
            1000.0,
            self::TODAY,
            $parcel,
        );
    }

    /** Посилка йде на пункт, з яким її створено: новий пункт замовлення з нею розійшовся б. */
    public function testLockedWhileParcelExists(): void
    {
        self::assertSame('op_err_locked', self::select(self::branch(), parcel: (object)['state' => PendingParcel::CREATED, 'barcode' => '711-2550636'])['error']);
        self::assertSame('op_err_locked_check', self::select(self::branch(), parcel: (object)['state' => PendingParcel::UNCERTAIN])['error']);
        self::assertSame('op_err_locked_check', self::select(self::branch(), parcel: (object)['state' => PendingParcel::PENDING])['error']);
        self::assertNull(self::select(self::branch())['error']);
        self::assertTrue(OrderPointChange::locks((object)['state' => PendingParcel::CREATED]));
        self::assertFalse(OrderPointChange::locks(null));
    }

    public function testBranchIsChosen(): void
    {
        $result = self::select(self::branch());

        self::assertNull($result['error']);
        self::assertNull($result['warning']);
        self::assertSame('aaaaaaaa-0000-4000-8000-000000000001', $result['selection']->branch->id);
    }

    public function testTooHeavyIsSavedWithWarning(): void
    {
        $result = self::select(self::branch(), weight: 40.0);

        self::assertNull($result['error']);
        self::assertSame('op_warn_too_heavy', $result['warning']);
        self::assertNotNull($result['selection']);
    }

    /** Пункт тимчасово не приймає посилок: менеджер це не обійде, як і покупець. */
    public function testInboundRestrictionIsRefused(): void
    {
        $result = self::select(self::branch(['inbound_from' => '2026-11-10 00:00:00', 'inbound_to' => '2026-11-20 00:00:00']), weight: 40.0);

        self::assertSame('op_err_inbound', $result['error']);
        self::assertNull($result['selection']);
    }

    public function testKindOfDisabledGroupIsRefused(): void
    {
        self::assertSame('op_err_branch', self::select(self::branch(['kind' => 'postomat']), postomatsEnabled: false)['error']);
        self::assertSame('op_err_branch', self::select(self::branch(['sending_only' => 1]))['error']);
    }

    public function testBranchOfOtherCityIsRefused(): void
    {
        self::assertSame('op_err_branch_city', self::select(self::branch(), city: self::city('bbbbbbbb-0000-4000-8000-000000000002'))['error']);
    }

    public function testForeignDeliveryIsRefused(): void
    {
        self::assertSame('order_point_foreign', self::select(self::branch(), delivery: (object)['id' => 9, 'module_id' => 12])['error']);
    }

    public function testPaidOrderPriceNotChanged(): void
    {
        $order = (object)['total_price' => 1000.0, 'delivery_price' => 60.0, 'separate_delivery' => 1, 'paid' => 1];

        $decision = OrderPriceRule::decide($order, self::delivery(), 95.0, false);
        self::assertSame(['action' => 'show', 'price' => 95.0], $decision);
        self::assertNull(OrderPointChange::autoPrice($decision));

        $unpaid = OrderPriceRule::decide((object)(['paid' => 0] + (array)$order), self::delivery(), 95.0, false);
        self::assertSame(95.0, OrderPointChange::autoPrice($unpaid), 'control: an unpaid separate order is repriced');
    }
}
