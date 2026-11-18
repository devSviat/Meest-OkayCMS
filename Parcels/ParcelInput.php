<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/** Форма посилки, як її надіслано: лише відомі поля, текст обрізано до ліміту; решту перевіряє ParcelDraft::withInput. */
final class ParcelInput
{
    public const int DESCRIPTION_MAX = 250;
    public const int NOTATION_MAX = 255;

    private const array TEXT = [
        'receiver_name' => 250,
        'receiver_phone' => 20,
        'payer' => 16,
        'pay_type' => 16,
        'description' => self::DESCRIPTION_MAX,
        'notation' => self::NOTATION_MAX,
    ];
    private const array NUMBERS = ['weight', 'length', 'width', 'height', 'declared_value'];

    /** @return array<string, string|float> */
    public static function fromPost(array $post): array
    {
        $input = [];
        foreach (self::TEXT as $name => $limit) {
            if (is_string($post[$name] ?? null)) {
                $input[$name] = mb_substr(trim($post[$name]), 0, $limit);
            }
        }
        foreach (self::NUMBERS as $name) {
            if (is_string($post[$name] ?? null)) {
                $raw = str_replace(',', '.', trim($post[$name]));
                // Не число лишається текстом, щоб чернетка повідомила про поле, а не зберегла старе значення.
                $input[$name] = is_numeric($raw) && is_finite((float)$raw) ? (float)$raw : mb_substr($raw, 0, 20);
            }
        }

        return $input;
    }
}
