<?php

namespace Okay\Modules\Sviat\Meest\Quote;

/** Загальні для магазину значення посилки: з них рахується ціна доставки й заповнюється форма посилки. */
final readonly class ShippingConfig
{
    public const string SENDER_NAME = 'sviat__meest__sender_name';
    public const string SENDER_PHONE = 'sviat__meest__sender_phone';
    public const string SENDER_CITY_ID = 'sviat__meest__sender_city_id';
    public const string SENDER_BRANCH_ID = 'sviat__meest__sender_branch_id';
    public const string DEFAULT_WEIGHT = 'sviat__meest__default_weight';
    public const string BOX_LENGTH = 'sviat__meest__box_length';
    public const string BOX_WIDTH = 'sviat__meest__box_width';
    public const string BOX_HEIGHT = 'sviat__meest__box_height';
    public const string RECEIVER_PAY = 'sviat__meest__receiver_pay';
    public const string PAY_TYPE = 'sviat__meest__pay_type';
    public const string DECLARE_TOTAL = 'sviat__meest__declare_total';
    public const string DECLARED_VALUE = 'sviat__meest__declared_value';
    public const string DESCRIPTION = 'sviat__meest__description';
    public const string NOTATION = 'sviat__meest__notation';
    public const string CUTOFF = 'sviat__meest__cutoff';

    public const string CASH = 'cash';
    public const string NONCASH = 'noncash';
    public const array PAY_TYPES = [self::CASH, self::NONCASH];
    public const float DEFAULT_WEIGHT_KG = 1.0;
    public const int DEFAULT_LENGTH_CM = 30;
    public const int DEFAULT_WIDTH_CM = 20;
    public const int DEFAULT_HEIGHT_CM = 15;
    public const string DEFAULT_NOTATION = '{order}';
    public const string DEFAULT_CUTOFF = '16:00';

    public function __construct(
        public string $senderName,
        public string $senderPhone,
        public string $senderCityId,
        public string $senderBranchId,
        public float $defaultWeightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public bool $receiverPay,
        public string $payType,
        public bool $declareTotal,
        public float $declaredValue,
        public string $description,
        public string $notation,
        /** HH:MM за Києвом: після цієї години посилка вважається завтрашньою. */
        public string $cutoff = self::DEFAULT_CUTOFF,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        $text = static fn(string $key): string => is_scalar($value = $get($key)) ? trim((string)$value) : '';
        // Не збережене налаштування — це типове значення власника, а не «ні».
        $flag = static fn(string $key, bool $default): bool => ($raw = $text($key)) === '' ? $default : (bool)(int)$raw;
        $weight = (float)$text(self::DEFAULT_WEIGHT);
        $payType = strtolower($text(self::PAY_TYPE));
        $notation = $text(self::NOTATION);

        return new self(
            $text(self::SENDER_NAME),
            $text(self::SENDER_PHONE),
            strtolower($text(self::SENDER_CITY_ID)),
            strtolower($text(self::SENDER_BRANCH_ID)),
            $weight > 0 ? $weight : self::DEFAULT_WEIGHT_KG,
            self::side($text(self::BOX_LENGTH), self::DEFAULT_LENGTH_CM),
            self::side($text(self::BOX_WIDTH), self::DEFAULT_WIDTH_CM),
            self::side($text(self::BOX_HEIGHT), self::DEFAULT_HEIGHT_CM),
            $flag(self::RECEIVER_PAY, true),
            in_array($payType, self::PAY_TYPES, true) ? $payType : self::CASH,
            $flag(self::DECLARE_TOTAL, true),
            max(0.0, (float)$text(self::DECLARED_VALUE)),
            $text(self::DESCRIPTION),
            $notation === '' ? self::DEFAULT_NOTATION : $notation,
            self::cutoff($text(self::CUTOFF)) ?? self::DEFAULT_CUTOFF,
        );
    }

    /** Година у вигляді HH:MM або null, якщо це не година доби. */
    public static function cutoff(string $value): ?string
    {
        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m) === 1 ? sprintf('%02d:%s', (int)$m[1], $m[2]) : null;
    }

    /** Об'єм коробки, м³: Д×Ш×В у сантиметрах ділиться на 10⁶. */
    public function volumeM3(): float
    {
        return $this->lengthCm * $this->widthCm * $this->heightCm / 1_000_000;
    }

    private static function side(string $value, int $default): int
    {
        $side = (int)ceil((float)$value);

        return $side > 0 ? $side : $default;
    }
}
