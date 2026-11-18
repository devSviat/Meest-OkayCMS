<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/** Посилка замовлення, якою вона піде в Meest: значення з замовлення й налаштувань, правлені менеджером у формі. */
final readonly class ParcelDraft
{
    public const int NAME_MIN = 2;
    public const int NAME_MAX = 250;

    public function __construct(
        public string $receiverName,
        public string $receiverPhone,
        /** Рядок BranchEntity: з нього беруться `id` і ліміти пункту. */
        public object $branch,
        public float $weightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public float $declaredValue,
        public bool $receiverPay,
        public string $payType,
        public string $description,
        public string $notation,
    ) {
    }

    /** @return array<string, string> мовні ключі помилок за полем форми */
    public function errors(): array
    {
        $errors = [];
        $name = trim((string)preg_replace('/\s+/u', ' ', $this->receiverName));
        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX || count(explode(' ', $name)) < 2) {
            $errors['receiver_name'] = 'sviat__meest__parcel_err_name';
        }
        $phone = MeestPhone::normalize($this->receiverPhone);
        if ($phone === null || !MeestPhone::isMobile($phone)) {
            $errors['receiver_phone'] = 'sviat__meest__parcel_err_phone';
        }
        if ((int)($this->branch->sending_only ?? 0) === 1) {
            $errors['point'] = 'sviat__meest__parcel_err_sending_only';
        }
        if (!is_finite($this->weightKg) || $this->weightKg <= 0 || $this->overWeight()) {
            $errors['weight'] = 'sviat__meest__parcel_err_weight';
        }
        if (!$this->sidesFit()) {
            $errors['sides'] = 'sviat__meest__parcel_err_sides';
        }
        $insurance = self::limit($this->branch->insurance_max ?? null);
        if (!is_finite($this->declaredValue) || $this->declaredValue < 0 || ($insurance !== null && $this->declaredValue > $insurance + 1e-9)) {
            $errors['declared_value'] = 'sviat__meest__parcel_err_declared';
        }
        if (!in_array($this->payType, ShippingConfig::PAY_TYPES, true)) {
            $errors['pay_type'] = 'sviat__meest__parcel_err_pay_type';
        }
        $description = trim($this->description);
        if ($description === '' || mb_strlen($description) > ParcelInput::DESCRIPTION_MAX) {
            $errors['description'] = 'sviat__meest__parcel_err_description';
        }

        return $errors;
    }

    /**
     * Накладає надіслану форму; поля, яких у ній немає, лишаються як були.
     *
     * @param array<string, string|float> $form ParcelInput::fromPost()
     * @return self|array<string, string> чернетка або помилки за полем
     */
    public function withInput(array $form): self|array
    {
        $errors = [];
        $number = static function (string $field, string $errorField, string $message, bool $whole = false) use ($form, &$errors): ?float {
            if (!array_key_exists($field, $form)) {
                return null;
            }
            // INF і NAN проходять is_numeric, але json_encode тіла запиту на них падає; дробова сторона мовчки округлилась би.
            if (!is_float($form[$field]) || !is_finite($form[$field]) || ($whole && $form[$field] !== floor($form[$field]))) {
                $errors[$errorField] = $message;

                return null;
            }

            return $form[$field];
        };
        $text = static fn(string $field, string $current): string => is_string($form[$field] ?? null) ? $form[$field] : $current;

        $weight = $number('weight', 'weight', 'sviat__meest__parcel_err_weight');
        $length = $number('length', 'sides', 'sviat__meest__parcel_err_sides', true);
        $width = $number('width', 'sides', 'sviat__meest__parcel_err_sides', true);
        $height = $number('height', 'sides', 'sviat__meest__parcel_err_sides', true);
        $declared = $number('declared_value', 'declared_value', 'sviat__meest__parcel_err_declared');
        if (array_key_exists('payer', $form) && !in_array($form['payer'], ['receiver', 'sender'], true)) {
            $errors['payer'] = 'sviat__meest__parcel_err_payer';
        }
        $phone = $text('receiver_phone', $this->receiverPhone);
        if (MeestPhone::normalize($phone) === null || !MeestPhone::isMobile((string)MeestPhone::normalize($phone))) {
            $errors['receiver_phone'] = 'sviat__meest__parcel_err_phone';
        }
        if ($errors !== []) {
            return $errors;
        }

        $draft = new self(
            $text('receiver_name', $this->receiverName),
            MeestPhone::normalize($phone) ?? $phone,
            $this->branch,
            $weight !== null ? round($weight, 3) : $this->weightKg,
            $length !== null ? (int)round($length) : $this->lengthCm,
            $width !== null ? (int)round($width) : $this->widthCm,
            $height !== null ? (int)round($height) : $this->heightCm,
            $declared !== null ? round($declared, 2) : $this->declaredValue,
            array_key_exists('payer', $form) ? $form['payer'] === 'receiver' : $this->receiverPay,
            $text('pay_type', $this->payType),
            $text('description', $this->description),
            $text('notation', $this->notation),
        );
        $errors = $draft->errors();

        return $errors === [] ? $draft : $errors;
    }

    /** @return array<string, mixed> тіло `POST parcel` */
    public function toRequest(string $parcelNumber, int $orderId, ParcelConfig $c): array
    {
        $body = [
            'parcelNumber' => $parcelNumber,
            'orderNumber' => (string)$orderId,
            'payType' => $this->payType,
            'receiverPay' => $this->receiverPay,
        ];
        if ($c->contractId !== '') {
            $body['contractID'] = $c->contractId;
        }
        $volume = $this->volumeM3();
        $description = mb_substr(trim($this->description), 0, ParcelInput::DESCRIPTION_MAX);

        // Для service=Branch схема вимагає лише branchID: addressID і building належать до адресної доставки.
        return $body + [
            'sender' => self::party($c->senderName, $c->senderPhone, $c->senderBranchId),
            'receiver' => self::party($this->receiverName, $this->receiverPhone, (string)$this->branch->id),
            // Схема вважає обов'язковими placeWeight і placeVolume, а приклад Meest шле ще й weight і volume.
            'placesItems' => [[
                'quantity' => 1,
                'weight' => $this->weightKg,
                'volume' => $volume,
                'placeWeight' => $this->weightKg,
                'placeVolume' => $volume,
                'insurance' => $this->declaredValue,
                'length' => $this->lengthCm,
                'width' => $this->widthCm,
                'height' => $this->heightCm,
            ]],
            'contentsItems' => [['contentName' => $description, 'quantity' => 1, 'weight' => $this->weightKg, 'value' => $this->declaredValue]],
            'notation' => mb_substr($this->notation, 0, ParcelInput::NOTATION_MAX),
        ];
    }

    public function volumeM3(): float
    {
        return round($this->lengthCm * $this->widthCm * $this->heightCm / 1_000_000, 6);
    }

    /** @return array{name: string, phone: string, service: string, branchID: string} */
    private static function party(string $name, string $phone, string $branchId): array
    {
        return [
            'name' => trim((string)preg_replace('/\s+/u', ' ', $name)),
            'phone' => MeestPhone::normalize($phone) ?? $phone,
            'service' => 'Branch',
            'branchID' => strtolower($branchId),
        ];
    }

    private function overWeight(): bool
    {
        foreach (['weight_place_max', 'weight_total_max'] as $field) {
            $max = self::limit($this->branch->$field ?? null);
            if ($max !== null && $this->weightKg > $max + 1e-9) {
                return true;
            }
        }

        return false;
    }

    /** Коробку можна повернути: найдовша сторона йде проти найдовшого ліміту; сторона без ліміту бере будь-яку. */
    private function sidesFit(): bool
    {
        $sides = [$this->lengthCm, $this->widthCm, $this->heightCm];
        if (min($sides) < 1) {
            return false;
        }
        $limits = array_map(
            fn(string $field): float => self::limit($this->branch->$field ?? null) ?? INF,
            ['length_max', 'width_max', 'height_max'],
        );
        sort($sides);
        sort($limits);
        foreach ($sides as $i => $side) {
            if ($side > $limits[$i] + 1e-9) {
                return false;
            }
        }

        return true;
    }

    /** Ліміт довідника: NULL і 0 означають «без обмеження». */
    private static function limit(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }
}
