<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/FakeDictionaryEntities.php';
require_once __DIR__ . '/SenderBranchesTest.php';

use Modules\Sviat\Meest\Support\FakeDictionaryEntities;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Backend\SenderBranches;
use Okay\Modules\Sviat\Meest\Backend\SenderSettings;
use PHPUnit\Framework\TestCase;

class SenderSettingsTest extends TestCase
{
    use FakeDictionaryEntities;

    private const string BRANCH = '0a1b2c3d-0000-4000-8000-0000000000b1';
    private const string RECEIVING_ONLY = '0a1b2c3d-0000-4000-8000-0000000000b2';
    private const string FOREIGN_BRANCH = '0a1b2c3d-0000-4000-8000-0000000000b4';

    /** @var array<string, mixed> */
    private array $stored = [];

    private function sender(): SenderSettings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);
        $settings->method('set')->willReturnCallback(function (string $name, $value): void {
            $this->stored[$name] = $value;
        });
        $cities = [
            ['id' => SenderBranchesTest::CITY, 'name_ua' => 'Вигадане', 'search_text' => 'вигадане'],
            ['id' => SenderBranchesTest::OTHER_CITY, 'name_ua' => 'Інше', 'search_text' => 'інше'],
        ];

        return new SenderSettings($settings, new SenderBranches($this->dictionaryFactory($cities, SenderBranchesTest::branches())));
    }

    /** @return array<string, string> */
    private static function post(array $override = []): array
    {
        return $override + [
            'sender_name' => 'ТОВ Тестовий Відправник',
            'sender_phone' => '+38 (067) 123-45-67',
            'sender_city_id' => SenderBranchesTest::CITY,
            'sender_branch_id' => self::BRANCH,
        ];
    }

    public function testValidPostSavesNormalizedPhone(): void
    {
        $sender = $this->sender();

        self::assertSame([], $sender->save(self::post()));
        self::assertSame('+380671234567', $this->stored[SenderSettings::PHONE]);
        self::assertSame(self::BRANCH, $this->stored[SenderSettings::BRANCH_ID]);
    }

    public function testIdsAreLowercased(): void
    {
        $sender = $this->sender();
        $upper = self::post(['sender_branch_id' => strtoupper(self::BRANCH), 'sender_city_id' => strtoupper(SenderBranchesTest::CITY)]);

        self::assertSame([], $sender->save($upper));
        self::assertSame(self::BRANCH, $this->stored[SenderSettings::BRANCH_ID]);
    }

    public function testNameLength(): void
    {
        $sender = $this->sender();

        self::assertSame(['sender_name' => 'sviat__meest__sender_err_name'], $sender->validate(self::post(['sender_name' => ''])));
        self::assertSame(['sender_name' => 'sviat__meest__sender_err_name'], $sender->validate(self::post(['sender_name' => str_repeat('я', 251)])));
        self::assertSame([], $sender->validate(self::post(['sender_name' => str_repeat('я', 250)])));
    }

    public function testBadPhoneRefused(): void
    {
        self::assertSame(['sender_phone' => 'sviat__meest__sender_err_phone'], $this->sender()->validate(self::post(['sender_phone' => '12345'])));
    }

    public function testUnknownCityRefused(): void
    {
        $errors = $this->sender()->validate(self::post(['sender_city_id' => '0a1b2c3d-0000-4000-8000-0000000000ff']));

        self::assertArrayHasKey('sender_city_id', $errors);
    }

    public function testBranchMustBelongToCity(): void
    {
        $errors = $this->sender()->validate(self::post(['sender_branch_id' => self::FOREIGN_BRANCH]));

        self::assertSame(['sender_branch_id' => 'sviat__meest__sender_err_branch'], $errors);
    }

    public function testReceivingOnlyRefused(): void
    {
        $errors = $this->sender()->validate(self::post(['sender_branch_id' => self::RECEIVING_ONLY]));

        self::assertSame(['sender_branch_id' => 'sviat__meest__sender_err_branch'], $errors);
    }

    public function testNothingSavedWhenInvalid(): void
    {
        $sender = $this->sender();

        $sender->save(self::post(['sender_phone' => 'x', 'sender_branch_id' => self::RECEIVING_ONLY]));

        self::assertSame([], $this->stored);
    }

    public function testReadReturnsStoredValues(): void
    {
        $sender = $this->sender();
        $sender->save(self::post());

        $read = $sender->read();

        self::assertSame('ТОВ Тестовий Відправник', $read['name']);
        self::assertSame('+380671234567', $read['phone']);
        self::assertSame(SenderBranchesTest::CITY, $read['city_id']);
    }
}
