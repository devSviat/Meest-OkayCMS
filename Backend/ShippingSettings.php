<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\KeyCheck\ContractInfo;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/** Блок «Параметри посилки за замовчуванням»: з цих значень заповнюється форма посилки в замовленні. */
final class ShippingSettings
{
    public const int DESCRIPTION_MAX = 250;
    public const int NOTATION_MAX = 100;
    /** Межа сторони коробки, см: далі вже помилка введення, а не посилка. */
    private const int SIDE_MAX = 999;
    /** `placesItems.weight` у схемі Meest — float(8,3). */
    public const float WEIGHT_MAX = 99999.999;
    /** `placesItems.insurance` у схемі Meest — float(9,2). */
    public const float DECLARED_MAX = 9999999.99;

    private const array SIDES = [
        'box_length' => ShippingConfig::BOX_LENGTH,
        'box_width' => ShippingConfig::BOX_WIDTH,
        'box_height' => ShippingConfig::BOX_HEIGHT,
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function read(): ShippingConfig
    {
        return ShippingConfig::fromSettings($this->settings->get(...));
    }

    /**
     * Значення полів форми зі збережених налаштувань.
     *
     * @return array{weight:string, length:string, width:string, height:string, receiver_pay:bool, pay_type:string, declare_total:bool, declared_value:string, description:string, notation:string, cutoff:string}
     */
    public static function form(ShippingConfig $config): array
    {
        return [
            'weight' => (string)$config->defaultWeightKg,
            'length' => (string)$config->lengthCm,
            'width' => (string)$config->widthCm,
            'height' => (string)$config->heightCm,
            'receiver_pay' => $config->receiverPay,
            'pay_type' => $config->payType,
            'declare_total' => $config->declareTotal,
            'declared_value' => (string)$config->declaredValue,
            'description' => $config->description,
            'notation' => $config->notation,
            'cutoff' => $config->cutoff,
        ];
    }

    /**
     * Надіслане як є: форму з помилками показуємо з тим, що менеджер ввів, навіть із хибними значеннями.
     *
     * @param array<string, mixed> $post
     * @return array{weight:string, length:string, width:string, height:string, receiver_pay:bool, pay_type:string, declare_total:bool, declared_value:string, description:string, notation:string, cutoff:string}
     */
    public static function formFromPost(array $post): array
    {
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? $post[$field] : '';

        return [
            'weight' => $text('default_weight'),
            'length' => $text('box_length'),
            'width' => $text('box_width'),
            'height' => $text('box_height'),
            'receiver_pay' => !empty($post['receiver_pay']),
            'pay_type' => $text('pay_type'),
            'declare_total' => !empty($post['declare_total']),
            'declared_value' => $text('declared_value'),
            'description' => $text('description'),
            'notation' => $text('notation'),
            'cutoff' => $text('cutoff'),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> поле форми → мовний ключ помилки; якщо вони є, нічого не зберігається
     */
    public function save(array $post, ?ContractInfo $contract): array
    {
        $errors = $this->validate($post, $contract);
        if ($errors !== []) {
            return $errors;
        }
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        $this->settings->set(ShippingConfig::DEFAULT_WEIGHT, self::decimal($text('default_weight')));
        foreach (self::SIDES as $field => $setting) {
            $this->settings->set($setting, (int)$text($field));
        }
        $this->settings->set(ShippingConfig::RECEIVER_PAY, empty($post['receiver_pay']) ? 0 : 1);
        if (array_key_exists('pay_type', $post)) {
            $this->settings->set(ShippingConfig::PAY_TYPE, $text('pay_type'));
        }
        $this->settings->set(ShippingConfig::DECLARE_TOTAL, empty($post['declare_total']) ? 0 : 1);
        $this->settings->set(ShippingConfig::DECLARED_VALUE, self::decimal($text('declared_value')));
        $this->settings->set(ShippingConfig::DESCRIPTION, self::oneLine($text('description')));
        $this->settings->set(ShippingConfig::NOTATION, self::oneLine($text('notation')));
        $this->settings->set(ShippingConfig::CUTOFF, ShippingConfig::cutoff($text('cutoff')) ?? ShippingConfig::DEFAULT_CUTOFF);

        return [];
    }

    /**
     * Форма оплати перевіряється, лише коли поле прийшло: договір, який не повідомив `isAvailable*`, нічого не забороняє.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public function validate(array $post, ?ContractInfo $contract): array
    {
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        $errors = [];
        $weight = self::decimal($text('default_weight'));
        if ($weight === null || $weight <= 0 || $weight > self::WEIGHT_MAX) {
            $errors['default_weight'] = 'sviat__meest__shipping_err_weight';
        }
        foreach (array_keys(self::SIDES) as $field) {
            $side = preg_match('/^\d{1,3}$/', $text($field)) === 1 ? (int)$text($field) : 0;
            if ($side < 1 || $side > self::SIDE_MAX) {
                $errors[$field] = 'sviat__meest__shipping_err_side';
            }
        }
        if (array_key_exists('pay_type', $post)) {
            $payType = $text('pay_type');
            if (!in_array($payType, ShippingConfig::PAY_TYPES, true)) {
                $errors['pay_type'] = 'sviat__meest__shipping_err_pay_type';
            } elseif (self::forbidden($payType, $contract)) {
                $errors['pay_type'] = 'sviat__meest__shipping_err_pay_type_forbidden';
            }
        }
        $declaredRaw = $text('declared_value') === '' ? '0' : $text('declared_value');
        $declared = self::decimal($declaredRaw);
        if ($declared === null || $declared < 0 || $declared > self::DECLARED_MAX || preg_match('/[.,]\d{3}$/', $declaredRaw) === 1) {
            $errors['declared_value'] = 'sviat__meest__shipping_err_declared_value';
        }
        if (mb_strlen(self::oneLine($text('description'))) > self::DESCRIPTION_MAX) {
            $errors['description'] = 'sviat__meest__shipping_err_description';
        }
        if (mb_strlen(self::oneLine($text('notation'))) > self::NOTATION_MAX) {
            $errors['notation'] = 'sviat__meest__shipping_err_notation';
        }
        if ($text('cutoff') !== '' && ShippingConfig::cutoff($text('cutoff')) === null) {
            $errors['cutoff'] = 'sviat__meest__shipping_err_cutoff';
        }

        return $errors;
    }

    /**
     * Півгодинні слоти доби; збережена година поза сіткою теж лишається у списку, щоб форма її не загубила.
     *
     * @return list<string>
     */
    public static function cutoffChoices(string $current): array
    {
        $choices = array_map(static fn(int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60), range(0, 23 * 60 + 30, 30));
        $current = ShippingConfig::cutoff($current);
        if ($current !== null && !in_array($current, $choices, true)) {
            $choices[] = $current;
            sort($choices);
        }

        return $choices;
    }

    /** Лише явне «ні» від договору забороняє форму оплати; невідоме (`null`) не забороняє. */
    public static function forbidden(string $payType, ?ContractInfo $contract): bool
    {
        return match ($payType) {
            ShippingConfig::CASH => $contract?->cashAllowed === false,
            ShippingConfig::NONCASH => $contract?->noncashAllowed === false,
            default => false,
        };
    }

    /** Число з комою чи крапкою, як вводять в українській локалі; усе інше, зокрема мінус, — null. */
    private static function decimal(string $raw): ?float
    {
        return preg_match('/^\d{1,9}(?:[.,]\d{1,3})?$/', $raw) === 1 ? (float)str_replace(',', '.', $raw) : null;
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
