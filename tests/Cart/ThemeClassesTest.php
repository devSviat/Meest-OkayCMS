<?php

namespace Modules\Sviat\Meest\Cart;

use Okay\Modules\Sviat\Meest\Cart\ThemeClasses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemeClassesTest extends TestCase
{
    public static function themes(): array
    {
        return [
            ['okay_shop', 'okay'], ['shop_child', 'okay'], ['shop_child_new', 'okay'], ['my_custom_theme', 'okay'], ['', 'okay'],
            ['vibe_shop', 'vibe'], ['vibe_shop_custom', 'vibe'],
        ];
    }

    #[DataProvider('themes')]
    public function testFamily(string $theme, string $family): void
    {
        self::assertSame($family, ThemeClasses::for($theme)['family']);
    }

    public function testOkayFamilyUsesTheThemeFormClasses(): void
    {
        $classes = ThemeClasses::for('shop_child_new');

        self::assertSame('form__group', $classes['field']);
        self::assertSame('form__input form__placeholder--focus', $classes['input']);
        self::assertSame('form__placeholder', $classes['label']);
    }

    public function testVibeUsesItsOwnFieldsAndChips(): void
    {
        $classes = ThemeClasses::for('vibe_shop');

        self::assertSame('vs-form__row', $classes['field']);
        self::assertSame('vs-field vs-form__input', $classes['input']);
        self::assertSame('vs-form__label', $classes['label']);
        self::assertSame('vs-chip', $classes['chip']);
        self::assertSame('vs-chip--selected', $classes['chipSelected']);
    }

    public function testEveryFamilyHasEveryKey(): void
    {
        self::assertSame(array_keys(ThemeClasses::for('okay_shop')), array_keys(ThemeClasses::for('vibe_shop')));
    }

    public function testCheckboxes(): void
    {
        self::assertSame('checkbox', ThemeClasses::for('okay_shop')['check'], "the theme's own component");
        self::assertSame('checkbox', ThemeClasses::for('shop_child_new')['check']);
        self::assertSame('me-check', ThemeClasses::for('vibe_shop')['check'], 'vibe_shop has none: the module draws it in theme colours');
    }

    public function testUnknownThemeIsOkayFamily(): void
    {
        self::assertSame(ThemeClasses::for('okay_shop'), ThemeClasses::for('some_new_theme'));
    }
}
