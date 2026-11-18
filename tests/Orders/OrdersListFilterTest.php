<?php

namespace Modules\Sviat\Meest\Orders;

use Okay\Modules\Sviat\Meest\Orders\OrdersListFilter;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;
use PHPUnit\Framework\TestCase;

class OrdersListFilterTest extends TestCase
{
    private const int MODULE = 42;

    public function testBarcodeIsRecognised(): void
    {
        self::assertSame('7112550636', OrdersListFilter::barcode(' 711 2550636 '));
        self::assertSame('711-2550636', OrdersListFilter::barcode('711-2550636'));
        self::assertSame('CV123456789UA', OrdersListFilter::barcode('cv123456789ua'));
        // Ім'я чи прізвище латиницею — не номер відправлення: без цифр пошук у посилках не запускається.
        foreach (['Олена', 'Shevchenko', '7112', "711' OR 1=1", null, ['7112550636']] as $keyword) {
            self::assertNull(OrdersListFilter::barcode($keyword));
        }
    }

    public function testGroupFilter(): void
    {
        self::assertSame(['meest_status_groups' => ['in_transit']], OrdersListFilter::byStatus([], 'in_transit', self::MODULE));
        self::assertSame(['page' => 1], OrdersListFilter::byStatus(['page' => 1], null, self::MODULE));
        foreach (ParcelStatus::FILTER_GROUPS as $group) {
            self::assertNotSame([], OrdersListFilter::byStatus([], $group, self::MODULE), $group);
        }
    }

    public function testNoneFilter(): void
    {
        self::assertSame('none', OrdersListFilter::NONE);
        self::assertSame(['meest_without_parcel' => self::MODULE], OrdersListFilter::byStatus([], 'none', self::MODULE));
    }

    public function testGroupComesOnlyFromTheList(): void
    {
        self::assertSame('received', OrdersListFilter::group('received'));
        self::assertSame('none', OrdersListFilter::group('none'));
        foreach (['', 'RECEIVED', 'unsettled', "received' OR 1=1", ['received'], null, 3, 0] as $raw) {
            self::assertNull(OrdersListFilter::group($raw));
            self::assertSame([], OrdersListFilter::byStatus([], $raw, self::MODULE));
        }
    }
}
