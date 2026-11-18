<?php

namespace Modules\Sviat\Meest\Orders;

use Okay\Modules\Sviat\Meest\Cart\CartSelection;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryView;
use Okay\Modules\Sviat\Meest\Quote\DeliveryQuote;
use PHPUnit\Framework\TestCase;

class OrderDeliveryStoreTest extends TestCase
{
    private const string CITY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string BRANCH = 'aaaaaaaa-0000-4000-8000-000000000001';

    private array $post = [];

    protected function setUp(): void
    {
        $this->post = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->post;
    }

    private static function city(): object
    {
        return (object)['id' => self::CITY, 'name_ua' => 'Вигадане', 'type_ua' => 'м.', 'district_ua' => 'Перший', 'region_ua' => 'Тестова'];
    }

    private static function branch(): object
    {
        return (object)['id' => self::BRANCH, 'city_id' => self::CITY, 'number' => '12', 'kind' => 'postomat', 'name_ua' => 'Поштомат №12', 'address_ua' => 'вул. Лісова 1'];
    }

    public function testSnapshotFromDictionaryNotPost(): void
    {
        $_POST = [
            'branch_title' => '<img src=x onerror=alert(1)>',
            'delivery_term' => '<script>alert(1)</script>',
            'meest_branch_title' => ['6' => 'Підробка'],
            'city_name' => 'Підробка',
        ];

        $row = OrderDeliveryStore::row(10, new CartSelection((object)['id' => 6], self::city(), self::branch()));

        self::assertSame([
            'order_id' => 10,
            'delivery_id' => 6,
            'city_id' => self::CITY,
            'city_name' => 'м. Вигадане, Перший, Тестова',
            'branch_id' => self::BRANCH,
            'branch_number' => '12',
            'branch_kind' => 'postomat',
            'branch_title' => 'Поштомат №12',
            'branch_address' => 'вул. Лісова 1',
        ], $row);
    }

    /** Довідник оновлюється щотижня; замовлення показує те, що обрав покупець, а не нинішню назву пункту. */
    public function testSnapshotKeepsNamesAfterDictionaryChange(): void
    {
        $city = self::city();
        $branch = self::branch();
        $row = (object)(OrderDeliveryStore::row(10, new CartSelection((object)['id' => 6], $city, $branch)) + ['price_source' => 'api', 'estimated_date' => null]);

        $city->name_ua = 'Нове';
        $branch->name_ua = 'Поштомат №99';
        $branch->address_ua = 'вул. Нова 2';

        $lines = OrderDeliveryView::lines($row, 60.0);
        self::assertSame('м. Вигадане, Перший, Тестова', $lines['city']);
        self::assertSame('Поштомат №12, вул. Лісова 1', $lines['point']);
    }

    public function testEstimatedDateOnlyAsDate(): void
    {
        self::assertSame(
            ['price' => 80.0, 'price_source' => 'api', 'estimated_date' => '2026-11-20'],
            OrderDeliveryStore::pricing(new DeliveryQuote(80.0, '2026-11-20', 'api', null), 80.0, 'api'),
        );
        foreach (['<script>alert(1)</script>', '20.11.2026', '2026-11-20 extra', '2026-02-31'] as $bad) {
            self::assertNull(OrderDeliveryStore::pricing(new DeliveryQuote(80.0, $bad, 'api', null), 80.0, 'api')['estimated_date'], $bad);
        }
        self::assertNull(OrderDeliveryStore::pricing(null, 60.0, null)['estimated_date']);
    }

    public function testViewShowsDateAndUnconfirmedPrice(): void
    {
        $row = (object)['city_name' => 'м. Вигадане', 'branch_title' => 'Відділення №1', 'branch_address' => '', 'price_source' => 'fallback', 'estimated_date' => '2026-11-20 00:00:00'];

        self::assertSame('2026-11-20', OrderDeliveryView::lines($row, 0.0)['estimatedDate']);
        self::assertTrue(OrderDeliveryView::lines($row, 0.0)['priceUnconfirmed']);
        self::assertFalse(OrderDeliveryView::lines($row, 70.0)['priceUnconfirmed'], 'the manager set a price');
        self::assertSame('Відділення №1', OrderDeliveryView::lines($row, 0.0)['point']);
    }

    public function testCityNameJoinsTypeDistrictAndRegion(): void
    {
        self::assertSame('м. Вигадане, Перший, Тестова', OrderDeliveryStore::cityName(self::city()));
        self::assertSame('', OrderDeliveryStore::cityName(null));
    }
}
