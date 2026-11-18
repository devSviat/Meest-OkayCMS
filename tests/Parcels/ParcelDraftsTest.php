<?php

namespace Modules\Sviat\Meest\Parcels;

require_once __DIR__ . '/../Support/OrderWorld.php';

use Modules\Sviat\Meest\Support\OrderWorld;
use Okay\Core\EntityFactory;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderParcel;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ParcelDraftsTest extends TestCase
{
    use OrderWorld;

    /** @var array<string, mixed> */
    private array $stored = [];
    private bool $hasUah = true;
    private ?object $branchRow = null;
    private float $variantWeight = 0.75;

    protected function setUp(): void
    {
        $this->stored = [
            ShippingConfig::DEFAULT_WEIGHT => '1',
            ShippingConfig::BOX_LENGTH => '40',
            ShippingConfig::BOX_WIDTH => '30',
            ShippingConfig::BOX_HEIGHT => '10',
            ShippingConfig::RECEIVER_PAY => '0',
            ShippingConfig::PAY_TYPE => 'noncash',
            ShippingConfig::DESCRIPTION => 'Одяг',
            ShippingConfig::NOTATION => 'Замовлення {order}',
        ];
        $this->hasUah = true;
        $this->branchRow = (object)['id' => self::BRANCH_ID, 'sending_only' => 0, 'weight_place_max' => '30.00', 'insurance_max' => '50000.00', 'length_max' => 120, 'width_max' => 60, 'height_max' => 60];
        $this->variantWeight = 0.75;
    }

    private function drafts(): ParcelDrafts
    {
        $base = $this->entityFactory();
        $purchases = self::createStub(PurchasesEntity::class);
        $purchases->method('noLimit')->willReturnSelf();
        $purchases->method('find')->willReturn([(object)['variant_id' => 5, 'amount' => 2]]);
        // mappedBy() у ядрі final, тож PHPUnit його не підмінить.
        $weight = $this->variantWeight;
        $variants = new class ($weight) {
            public function __construct(private readonly float $weight)
            {
            }

            public function mappedBy(string $column): static
            {
                return $this;
            }

            public function noLimit(): static
            {
                return $this;
            }

            public function find(array $filter): array
            {
                return [5 => (object)['id' => 5, 'weight' => $this->weight]];
            }
        };
        $currencies = self::createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturnCallback(fn() => $this->hasUah ? (object)['id' => 2] : null);
        $branches = self::createStub(BranchEntity::class);
        $branches->method('get')->willReturnCallback(fn() => $this->branchRow ?? false);

        $factory = self::createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            PurchasesEntity::class => $purchases,
            VariantsEntity::class => $variants,
            CurrenciesEntity::class => $currencies,
            BranchEntity::class => $branches,
            default => $base->get($class),
        });
        $money = self::createStub(Money::class);
        $money->method('convert')->willReturnCallback(static fn($amount) => (string)($amount * 40));
        $settings = self::createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $key) => $this->stored[$key] ?? null);
        $uah = new UahTotal($factory, $money);

        return new ParcelDrafts(
            $factory,
            $this->lines($factory),
            new OrderDeliveryStore($factory),
            new OrderParcel($factory, $uah),
            $uah,
            $settings,
        );
    }

    private function draft(): ParcelDraft
    {
        $draft = $this->drafts()->forOrder(77);
        self::assertInstanceOf(ParcelDraft::class, $draft);

        return $draft;
    }

    public function testForeignDeliveryRefused(): void
    {
        $this->switchedOrder();

        self::assertSame('sviat__meest__parcel_err_not_own', $this->drafts()->forOrder(77));
    }

    public function testNoSnapshotRefused(): void
    {
        $this->ownOrder();
        $this->snapshot = null;

        self::assertSame('sviat__meest__parcel_err_no_point', $this->drafts()->forOrder(77));
    }

    public function testBranchGoneFromDictionaryRefused(): void
    {
        $this->ownOrder();
        $this->branchRow = null;

        self::assertSame('sviat__meest__parcel_err_no_point', $this->drafts()->forOrder(77));
    }

    public function testDefaultsFromOrderAndSettings(): void
    {
        $this->ownOrder();
        $this->order->last_name = 'Тестенко';
        $this->order->name = 'Тест';
        $this->order->phone = '067 123-45-67';

        $draft = $this->draft();

        self::assertSame('Тестенко Тест', $draft->receiverName);
        self::assertSame('+380671234567', $draft->receiverPhone);
        self::assertSame(self::BRANCH_ID, $draft->branch->id);
        self::assertSame(1.5, $draft->weightKg);
        self::assertSame([40, 30, 10], [$draft->lengthCm, $draft->widthCm, $draft->heightCm]);
        self::assertFalse($draft->receiverPay);
        self::assertSame('noncash', $draft->payType);
        self::assertSame('Одяг', $draft->description);
        self::assertSame('Замовлення 77', $draft->notation);
    }

    public function testDefaultWeightWhenProductsHaveNone(): void
    {
        $this->ownOrder();
        $this->variantWeight = 0.0;

        self::assertSame(1.0, $this->draft()->weightKg);
    }

    public function testNameTrimmedWhenLastNameMissing(): void
    {
        $this->ownOrder();
        $this->order->last_name = '';
        $this->order->name = 'Тест';

        self::assertSame('Тест', $this->draft()->receiverName);
    }

    public function testDeclaredFromTotalOrSettings(): void
    {
        $this->ownOrder();
        $this->stored[ShippingConfig::DECLARE_TOTAL] = '1';
        self::assertSame(40000.0, $this->draft()->declaredValue);

        $this->stored[ShippingConfig::DECLARE_TOTAL] = '0';
        $this->stored[ShippingConfig::DECLARED_VALUE] = '300';
        self::assertSame(300.0, $this->draft()->declaredValue);
    }

    public function testFixedDeclaredValueNeedsNoUahCurrency(): void
    {
        $this->ownOrder();
        $this->hasUah = false;
        $this->stored[ShippingConfig::DECLARE_TOTAL] = '0';
        $this->stored[ShippingConfig::DECLARED_VALUE] = '300';

        self::assertSame(300.0, $this->draft()->declaredValue);

        $this->stored[ShippingConfig::DECLARE_TOTAL] = '1';
        self::assertSame('sviat__meest__parcel_err_no_uah', $this->drafts()->forOrder(77));
    }
}
