<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParcelStatusTest extends TestCase
{
    /** @return iterable<string, array{int, ?string}> */
    public static function explicitCodes(): iterable
    {
        yield '707 returning' => [707, 'returning'];
        yield '315 created' => [315, 'created'];
        yield '1100 in transit' => [1100, 'in_transit'];
        yield '12 in transit' => [12, 'in_transit'];
        yield '1622 received' => [1622, 'received'];
        yield '1825 returned' => [1825, 'returned'];
        yield '3 cancelled' => [3, 'cancelled'];
        yield '5700 cancelled' => [5700, 'cancelled'];
        yield '1629 problem' => [1629, 'problem'];
        yield '1630 problem' => [1630, 'problem'];
        yield '1220 storage' => [1220, 'storage'];
        yield '1213 arrived' => [1213, 'arrived'];
        yield '202 unmapped' => [202, null];
        yield '808 unmapped' => [808, null];
    }

    #[DataProvider('explicitCodes')]
    public function testExplicitCodes(int $code, ?string $group): void
    {
        self::assertSame($group, ParcelStatus::group($code));
    }

    public function testUnknownCodeKeepsGroup(): void
    {
        self::assertSame('arrived', ParcelStatus::next('arrived', 4242));
        self::assertNull(ParcelStatus::next(null, 4242));
        self::assertSame('storage', ParcelStatus::next('arrived', 1220));
    }

    public function testNoCodeBelongsToTwoGroups(): void
    {
        $seen = [];
        foreach (ParcelStatus::GROUPS as $group => $codes) {
            foreach ($codes as $code) {
                self::assertArrayNotHasKey($code, $seen, "code {$code} in {$group} and " . ($seen[$code] ?? ''));
                $seen[$code] = $group;
            }
        }
    }

    public function testFinalAndFilterGroups(): void
    {
        self::assertSame(['received', 'returned', 'cancelled'], ParcelStatus::FINAL);
        self::assertSame(
            ['created', 'in_transit', 'arrived', 'storage', 'problem', 'returning', 'received', 'returned', 'cancelled', 'none'],
            ParcelStatus::FILTER_GROUPS,
        );
        foreach (array_keys(ParcelStatus::GROUPS) as $group) {
            self::assertContains($group, ParcelStatus::FILTER_GROUPS);
        }
    }
}
