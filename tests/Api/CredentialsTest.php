<?php

namespace Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Api\Credentials;
use PHPUnit\Framework\TestCase;

class CredentialsTest extends TestCase
{
    /** @param array<string, string> $settings */
    private static function from(array $settings): Credentials
    {
        return Credentials::fromSettings(static fn(string $key): mixed => $settings[$key] ?? null);
    }

    public function testConfiguredByCabinetToken(): void
    {
        $credentials = self::from([Credentials::CABINET_TOKEN => ' tok-1 ']);

        self::assertTrue($credentials->configured());
        self::assertSame('tok-1', $credentials->cabinetToken);
        self::assertFalse(self::from([])->configured());
        self::assertFalse(self::from([Credentials::CABINET_TOKEN => '   ', Credentials::CONTRACT_ID => 'c35b6195-4ea3-11de-8591-001d600938f8'])->configured());
    }

    public function testSecretsListsOnlyFilledValues(): void
    {
        $credentials = self::from([
            Credentials::CABINET_TOKEN => 'tok-1',
            Credentials::CONTRACT_ID => 'c35b6195-4ea3-11de-8591-001d600938f8',
        ]);

        self::assertSame(['tok-1', 'c35b6195-4ea3-11de-8591-001d600938f8'], $credentials->secrets());
        self::assertSame(['c35b6195-4ea3-11de-8591-001d600938f8'], self::from([Credentials::CONTRACT_ID => 'c35b6195-4ea3-11de-8591-001d600938f8'])->secrets());
        self::assertSame([], self::from([])->secrets());
    }

    public function testContractIdFormat(): void
    {
        self::assertTrue(Credentials::validContractId('C35B6195-4EA3-11DE-8591-001D600938F8'));
        self::assertFalse(Credentials::validContractId('123'));
        self::assertFalse(Credentials::validContractId('../c35b6195-4ea3-11de-8591-001d600938f8'));
    }
}
