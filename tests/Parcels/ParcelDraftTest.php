<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Parcels\ParcelConfig;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use Okay\Modules\Sviat\Meest\Parcels\ParcelInput;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

/**
 * Структура `contentsItems`, звірена з openAPI_ua.json (POST /parcel):
 * масив об'єктів, обов'язкові поля contentName, quantity, weight (кг однієї одиниці), value (вартість одиниці).
 * `placesItems` вимагає quantity, placeWeight, placeVolume; `sender`/`receiver` за схемою вимагають ще
 * addressID і building, але для service=Branch їх не надсилаємо (опис методу: для Branch потрібен лише branchID).
 */
class ParcelDraftTest extends TestCase
{
    private const string RECEIVER_BRANCH = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string SENDER_BRANCH = 'cccccccc-0000-4000-8000-000000000003';
    private const string CONTRACT = 'dddddddd-0000-4000-8000-000000000004';

    /** @param array<string, mixed> $override */
    private static function branch(array $override = []): object
    {
        return (object)($override + [
            'id' => self::RECEIVER_BRANCH, 'weight_place_max' => '30.00', 'weight_total_max' => '30.00', 'insurance_max' => '50000.00',
            'length_max' => 120, 'width_max' => 60, 'height_max' => 60, 'sending_only' => 0,
        ]);
    }

    /** @param array<string, mixed> $override */
    private static function draft(array $override = [], ?object $branch = null): ParcelDraft
    {
        $v = $override + [
            'name' => 'Тестенко Тест', 'phone' => '+380671234567', 'weight' => 2.5, 'length' => 30, 'width' => 20, 'height' => 15,
            'declared' => 1000.0, 'receiverPay' => true, 'payType' => 'noncash', 'description' => 'Одяг', 'notation' => '1042',
        ];

        return new ParcelDraft(
            $v['name'], $v['phone'], $branch ?? self::branch(), $v['weight'], $v['length'], $v['width'], $v['height'],
            $v['declared'], $v['receiverPay'], $v['payType'], $v['description'], $v['notation'],
        );
    }

    private static function config(): ParcelConfig
    {
        $stored = [
            ShippingConfig::SENDER_NAME => 'Магазин Тестовий',
            ShippingConfig::SENDER_PHONE => '0501112233',
            ShippingConfig::SENDER_BRANCH_ID => strtoupper(self::SENDER_BRANCH),
            'sviat__meest__contract_id' => self::CONTRACT,
        ];

        return ParcelConfig::fromSettings(static fn(string $key): mixed => $stored[$key] ?? null);
    }

    public function testRequestBody(): void
    {
        $body = self::draft()->toRequest('1042-7', 1042, self::config());

        self::assertSame([
            'parcelNumber' => '1042-7',
            'orderNumber' => '1042',
            'payType' => 'noncash',
            'receiverPay' => true,
            'contractID' => self::CONTRACT,
            'sender' => ['name' => 'Магазин Тестовий', 'phone' => '+380501112233', 'service' => 'Branch', 'branchID' => self::SENDER_BRANCH],
            'receiver' => ['name' => 'Тестенко Тест', 'phone' => '+380671234567', 'service' => 'Branch', 'branchID' => self::RECEIVER_BRANCH],
            'placesItems' => [[
                'quantity' => 1, 'weight' => 2.5, 'volume' => 0.009, 'placeWeight' => 2.5, 'placeVolume' => 0.009,
                'insurance' => 1000.0, 'length' => 30, 'width' => 20, 'height' => 15,
            ]],
            'contentsItems' => [['contentName' => 'Одяг', 'quantity' => 1, 'weight' => 2.5, 'value' => 1000.0]],
            'notation' => '1042',
        ], $body);
    }

    public function testRequestHasNoDoorOrCodFields(): void
    {
        $json = json_encode(self::draft()->toRequest('1042-7', 1042, self::config()), JSON_THROW_ON_ERROR);

        foreach (['addressID', 'building', 'COD'] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden . '"', $json);
        }
    }

    public function testRequestOmitsContractWhenNotConfigured(): void
    {
        $config = ParcelConfig::fromSettings(static fn(string $key): mixed => null);

        self::assertArrayNotHasKey('contractID', self::draft()->toRequest('1-1', 1, $config));
    }

    public function testRequestPhoneAndSenderBranchNormalised(): void
    {
        $body = self::draft(['phone' => '067 123-45-67'])->toRequest('1-1', 1, self::config());

        self::assertSame('+380671234567', $body['receiver']['phone']);
        self::assertSame(self::SENDER_BRANCH, $body['sender']['branchID']);
    }

    public function testValidDraftHasNoErrors(): void
    {
        self::assertSame([], self::draft()->errors());
    }

    public function testSendingOnlyRefused(): void
    {
        $errors = self::draft([], self::branch(['sending_only' => 1]))->errors();

        self::assertSame(['point' => 'sviat__meest__parcel_err_sending_only'], $errors);
    }

    public function testRotatedBoxFits(): void
    {
        $branch = self::branch(['length_max' => 20, 'width_max' => 30, 'height_max' => 60]);

        self::assertSame([], self::draft(['length' => 60, 'width' => 20, 'height' => 30], $branch)->errors());
    }

    public function testOverLimitsRefused(): void
    {
        self::assertArrayHasKey('weight', self::draft(['weight' => 30.5])->errors());
        self::assertArrayHasKey('weight', self::draft(['weight' => 0.0])->errors());
        self::assertArrayHasKey('sides', self::draft(['length' => 121])->errors());
        self::assertArrayHasKey('sides', self::draft(['length' => 61, 'width' => 61])->errors());
        self::assertArrayHasKey('sides', self::draft(['height' => 0])->errors());
        self::assertArrayHasKey('declared_value', self::draft(['declared' => 50000.01])->errors());
        self::assertArrayHasKey('declared_value', self::draft(['declared' => -1.0])->errors());
    }

    public function testNullAndZeroLimitMeanNoLimit(): void
    {
        $branch = self::branch(['weight_place_max' => null, 'weight_total_max' => '0.00', 'insurance_max' => null, 'length_max' => 0, 'width_max' => null, 'height_max' => 0]);

        self::assertSame([], self::draft(['weight' => 500.0, 'length' => 900, 'declared' => 1e7], $branch)->errors());
    }

    public function testLandlineRefused(): void
    {
        self::assertSame(['receiver_phone' => 'sviat__meest__parcel_err_phone'], self::draft(['phone' => '+380441234567'])->errors());
        self::assertArrayHasKey('receiver_phone', self::draft(['phone' => 'не телефон'])->errors());
    }

    public function testNameNeedsTwoWordsAndLength(): void
    {
        self::assertArrayHasKey('receiver_name', self::draft(['name' => 'Тестенко'])->errors());
        self::assertSame([], self::draft(['name' => 'А Б'])->errors());
        self::assertArrayHasKey('receiver_name', self::draft(['name' => str_repeat('а', 130) . ' ' . str_repeat('б', 130)])->errors());
        self::assertSame([], self::draft(['name' => '  Тестенко   Тест Тестович '])->errors());
    }

    public function testPayTypeAndDescriptionChecked(): void
    {
        self::assertArrayHasKey('pay_type', self::draft(['payType' => 'nonCash'])->errors());
        self::assertArrayHasKey('description', self::draft(['description' => ' '])->errors());
        self::assertArrayHasKey('description', self::draft(['description' => str_repeat('а', 251)])->errors());
    }

    public function testWithInputOverlaysAndKeepsRest(): void
    {
        $draft = self::draft()->withInput(['weight' => 3.0, 'payer' => 'sender', 'receiver_phone' => '050 111 22 33']);

        self::assertInstanceOf(ParcelDraft::class, $draft);
        self::assertSame(3.0, $draft->weightKg);
        self::assertFalse($draft->receiverPay);
        self::assertSame('+380501112233', $draft->receiverPhone);
        self::assertSame('Тестенко Тест', $draft->receiverName);
        self::assertSame(30, $draft->lengthCm);
    }

    public function testWithInputReturnsErrors(): void
    {
        $result = self::draft()->withInput(['weight' => 'abc', 'receiver_phone' => '0441234567']);

        self::assertIsArray($result);
        ksort($result);
        self::assertSame(['receiver_phone' => 'sviat__meest__parcel_err_phone', 'weight' => 'sviat__meest__parcel_err_weight'], $result);
    }

    public function testWithInputRejectsNonFiniteNumbers(): void
    {
        $unlimited = self::branch(['weight_place_max' => null, 'weight_total_max' => null, 'insurance_max' => null, 'length_max' => 0, 'width_max' => 0, 'height_max' => 0]);
        $form = ParcelInput::fromPost(['weight' => '1e999', 'length' => '1e999', 'declared_value' => '1e999']);

        self::assertSame(
            ['weight' => 'sviat__meest__parcel_err_weight', 'sides' => 'sviat__meest__parcel_err_sides', 'declared_value' => 'sviat__meest__parcel_err_declared'],
            self::draft([], $unlimited)->withInput($form),
        );
        self::assertSame(['weight' => 'sviat__meest__parcel_err_weight'], self::draft([], $unlimited)->withInput(['weight' => INF]));
    }

    public function testWithInputRejectsFractionalSides(): void
    {
        self::assertSame(['sides' => 'sviat__meest__parcel_err_sides'], self::draft()->withInput(['length' => 30.5]));
        $ok = self::draft()->withInput(['length' => 31.0]);
        self::assertInstanceOf(ParcelDraft::class, $ok);
        self::assertSame(31, $ok->lengthCm);
    }

    public function testWithInputAcceptsOnlyKnownPayer(): void
    {
        self::assertSame(['payer' => 'sviat__meest__parcel_err_payer'], self::draft()->withInput(['payer' => 'nobody']));
        self::assertSame(['payer' => 'sviat__meest__parcel_err_payer'], self::draft()->withInput(['payer' => '']));
        $sender = self::draft()->withInput(['payer' => 'sender']);
        self::assertInstanceOf(ParcelDraft::class, $sender);
        self::assertFalse($sender->receiverPay);
        $receiver = self::draft(['receiverPay' => false])->withInput(['payer' => 'receiver']);
        self::assertTrue($receiver->receiverPay);
    }
}
