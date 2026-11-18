<?php

namespace Modules\Sviat\Meest\Live;

require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\Meest\Support\ArrayLogger;
use Modules\Sviat\Meest\Support\FixedCredentials;
use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\CurlTransport;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchRow;
use Okay\Modules\Sviat\Meest\Quote\Parcel;
use Okay\Modules\Sviat\Meest\Quote\QuoteRequest;
use Okay\Modules\Sviat\Meest\Quote\QuoteService;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

/**
 * Живий розрахунок лише на читання (`branchSearch`, `calculate` з COD 0): змінні задає власник у своїй оболонці.
 * Повідомлення assert містять типи й кількості, але не значення з відповідей Meest.
 */
class LiveQuoteTest extends TestCase
{
    public function testCalculateBetweenTwoBranches(): void
    {
        $token = (string)getenv('MEEST_API_TOKEN');
        $contractId = (string)getenv('MEEST_CONTRACT_ID');
        if ($token === '' || $contractId === '') {
            self::markTestSkipped('MEEST_API_TOKEN/MEEST_CONTRACT_ID не задані');
        }
        $credentials = new FixedCredentials(new Credentials($token, $contractId));
        $state = new InMemoryState();
        $now = static fn(): int => time();
        $flags = new AuthFlags($state);
        $client = new MeestClient(new CurlTransport(), new TokenProvider($credentials, $state, $flags, $now), new RequestBudget($state, new InMemoryMutex(), $now), $credentials, new ArrayLogger());
        $sweep = new RegionSweep($client, $state);

        $regionId = $sweep->regions()[0]['id'];
        $rows = array_values(array_filter(array_map(static fn(array $b): ?array => BranchRow::fromApi($b, $regionId), $sweep->branches($regionId))));
        $branches = array_values(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'branch' && !$row['sending_only'] && !$row['receiving_only']));
        self::assertGreaterThanOrEqual(2, count($branches), 'branches usable both ways: ' . count($branches));

        $settings = [ShippingConfig::SENDER_BRANCH_ID => $branches[0]['id']];
        $config = ShippingConfig::fromSettings(static fn(string $k): mixed => $settings[$k] ?? null);
        $service = new QuoteService($client, $flags, $credentials);
        $ask = static fn(?string $sendingDate) => $service->quote(new QuoteRequest(
            $config,
            $branches[1]['id'],
            Parcel::fromMetrics(new CartMetrics(1.0, false, 500.0, 500.0), $config),
            500.0,
            sendingDate: $sendingDate,
        ));
        $quote = $ask(null);

        self::assertSame('api', $quote->source, 'problem: ' . (string)$quote->problem);
        self::assertGreaterThan(0, $quote->price);
        self::assertTrue($quote->estimatedDate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $quote->estimatedDate) === 1, 'estimatedDate shape');

        $later = $ask(new \DateTimeImmutable('+7 days', new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y'));
        self::assertSame('api', $later->source, 'problem with sendingDate: ' . (string)$later->problem);
        if ($quote->estimatedDate !== null && $later->estimatedDate !== null) {
            self::assertGreaterThan($quote->estimatedDate, $later->estimatedDate, 'sending a week later moves the estimated date');
        }
    }
}
