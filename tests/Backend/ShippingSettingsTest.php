<?php

namespace Modules\Sviat\Meest\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Backend\ShippingSettings;
use Okay\Modules\Sviat\Meest\KeyCheck\ContractInfo;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ShippingSettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    private function settings(): ShippingSettings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);
        $settings->method('set')->willReturnCallback(function (string $name, $value): void {
            $this->stored[$name] = $value;
        });

        return new ShippingSettings($settings);
    }

    /** @return array<string, string> */
    private static function post(array $override = []): array
    {
        return $override + [
            'default_weight' => '1',
            'box_length' => '30',
            'box_width' => '20',
            'box_height' => '15',
            'receiver_pay' => '1',
            'pay_type' => 'cash',
            'declare_total' => '1',
            'declared_value' => '0',
            'description' => 'Товари',
            'notation' => '{order}',
            'cutoff' => '16:00',
        ];
    }

    private static function contract(?bool $cash, ?bool $noncash): ContractInfo
    {
        return new ContractInfo('ТОВ Тестовий', '00000001', null, false, $cash, $noncash);
    }

    public function testValidPostSaves(): void
    {
        $shipping = $this->settings();

        self::assertSame([], $shipping->save(self::post(), null));
        self::assertSame(1, $this->stored[ShippingConfig::RECEIVER_PAY]);
        self::assertSame('cash', $this->stored[ShippingConfig::PAY_TYPE]);
        self::assertSame(30, $this->stored[ShippingConfig::BOX_LENGTH]);
        self::assertSame(1.0, $this->stored[ShippingConfig::DEFAULT_WEIGHT]);
    }

    public function testWeightAndBoxPositive(): void
    {
        $shipping = $this->settings();

        foreach (['default_weight', 'box_length', 'box_width', 'box_height'] as $field) {
            foreach (['0', '-1', '', 'abc'] as $bad) {
                self::assertArrayHasKey($field, $shipping->validate(self::post([$field => $bad]), null), $field . '=' . $bad);
            }
        }
        self::assertSame([], $shipping->validate(self::post(['default_weight' => '0,5']), null), 'decimal comma');
    }

    public function testPayTypeForbiddenByContractRefused(): void
    {
        $shipping = $this->settings();

        self::assertSame(
            ['pay_type' => 'sviat__meest__shipping_err_pay_type_forbidden'],
            $shipping->validate(self::post(['pay_type' => 'noncash']), self::contract(true, false)),
        );
        self::assertSame(
            ['pay_type' => 'sviat__meest__shipping_err_pay_type_forbidden'],
            $shipping->validate(self::post(['pay_type' => 'cash']), self::contract(false, true)),
        );
        self::assertSame([], $shipping->validate(self::post(['pay_type' => 'noncash']), self::contract(false, true)));
    }

    public function testUnknownAvailabilityAllowsBoth(): void
    {
        $shipping = $this->settings();

        foreach (['cash', 'noncash'] as $type) {
            self::assertSame([], $shipping->validate(self::post(['pay_type' => $type]), self::contract(null, null)), $type);
            self::assertSame([], $shipping->validate(self::post(['pay_type' => $type]), null), $type);
        }
    }

    public function testUnknownPayTypeRefused(): void
    {
        self::assertSame(['pay_type' => 'sviat__meest__shipping_err_pay_type'], $this->settings()->validate(self::post(['pay_type' => 'barter']), null));
    }

    public function testPayTypeNotCheckedWhenFieldAbsent(): void
    {
        $post = self::post();
        unset($post['pay_type']);

        self::assertSame([], $this->settings()->validate($post, self::contract(false, false)));
    }

    public function testDeclaredValueNotNegative(): void
    {
        $shipping = $this->settings();

        self::assertArrayHasKey('declared_value', $shipping->validate(self::post(['declared_value' => '-1']), null));
        self::assertArrayHasKey('declared_value', $shipping->validate(self::post(['declared_value' => 'x']), null));
        self::assertSame([], $shipping->validate(self::post(['declared_value' => '0']), null));
        self::assertSame([], $shipping->validate(self::post(['declared_value' => '1500,50']), null));
    }

    public function testCheckboxOffMeansSenderPaysAndFixedValue(): void
    {
        $shipping = $this->settings();
        $post = self::post();
        unset($post['receiver_pay'], $post['declare_total']);

        self::assertSame([], $shipping->save($post, null));
        self::assertSame(0, $this->stored[ShippingConfig::RECEIVER_PAY]);
        self::assertSame(0, $this->stored[ShippingConfig::DECLARE_TOTAL]);
    }

    public function testNothingSavedWhenInvalid(): void
    {
        $this->settings()->save(self::post(['box_width' => '0']), null);

        self::assertSame([], $this->stored);
    }

    public function testDescriptionAndNotationAreCapped(): void
    {
        $shipping = $this->settings();

        self::assertArrayHasKey('description', $shipping->validate(self::post(['description' => str_repeat('я', 251)]), null));
        self::assertArrayHasKey('notation', $shipping->validate(self::post(['notation' => str_repeat('я', 101)]), null));
    }

    public function testWeightLimitFromApiType(): void
    {
        $shipping = $this->settings();

        // placesItems.weight — float(8,3)
        self::assertSame([], $shipping->validate(self::post(['default_weight' => '99999.999']), null));
        self::assertArrayHasKey('default_weight', $shipping->validate(self::post(['default_weight' => '100000']), null));
        self::assertArrayHasKey('default_weight', $shipping->validate(self::post(['default_weight' => '999999999']), null));
    }

    public function testDeclaredValueLimitFromApiType(): void
    {
        $shipping = $this->settings();

        // placesItems.insurance — float(9,2)
        self::assertSame([], $shipping->validate(self::post(['declared_value' => '9999999.99']), null));
        self::assertArrayHasKey('declared_value', $shipping->validate(self::post(['declared_value' => '10000000']), null));
        self::assertArrayHasKey('declared_value', $shipping->validate(self::post(['declared_value' => '10.005']), null), 'kopecks only');
    }

    public function testBoxSidesStayWithinOneToNineHundredNinetyNine(): void
    {
        $shipping = $this->settings();

        self::assertSame([], $shipping->validate(self::post(['box_length' => '999']), null));
        self::assertArrayHasKey('box_length', $shipping->validate(self::post(['box_length' => '1000']), null));
    }



    public function testCutoffSavedAndValidated(): void
    {
        $shipping = $this->settings();

        self::assertSame([], $shipping->save(self::post(['cutoff' => '9:30']), null));
        self::assertSame('09:30', $this->stored[ShippingConfig::CUTOFF]);
        self::assertSame('09:30', ShippingSettings::form($shipping->read())['cutoff']);
        self::assertSame(['cutoff' => 'sviat__meest__shipping_err_cutoff'], $shipping->validate(self::post(['cutoff' => '24:00']), null));
        self::assertSame(['cutoff' => 'sviat__meest__shipping_err_cutoff'], $shipping->validate(self::post(['cutoff' => '<b>']), null));
        self::assertSame('18:30', ShippingSettings::formFromPost(self::post(['cutoff' => '18:30']))['cutoff']);
    }
}
