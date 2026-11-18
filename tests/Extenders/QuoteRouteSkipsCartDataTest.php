<?php

namespace Modules\Sviat\Meest\Extenders;

use Okay\Core\Design;
use Okay\Core\Modules\Module;
use Okay\Core\Router;
use Okay\Modules\Sviat\Meest\Extenders\FrontExtender;
use PHPUnit\Framework\TestCase;

/**
 * `ajax/meest/quote` бере ціну зі списку кошика ядра, а той запускає й ланку, що готує дані для шаблону кошика
 * (prefill, останнє замовлення, популярні міста). Відповідь ціни їх не використовує.
 */
class QuoteRouteSkipsCartDataTest extends TestCase
{
    private mixed $route;

    protected function setUp(): void
    {
        $this->route = new \ReflectionProperty(Router::class, 'currentRouteName')->getValue();
    }

    protected function tearDown(): void
    {
        new \ReflectionProperty(Router::class, 'currentRouteName')->setValue(null, $this->route);
    }

    public function testQuoteRouteAssignsNothing(): void
    {
        new \ReflectionProperty(Router::class, 'currentRouteName')->setValue(null, 'Sviat_Meest_cart_quote');
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(81);
        $assigned = [];
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name) use (&$assigned, $design) {
            $assigned[] = $name;

            return $design;
        });
        // Решта залежностей без конструкторів: будь-яке звернення до них упало б.
        $front = new \ReflectionClass(FrontExtender::class)->newInstanceWithoutConstructor();
        foreach (['module' => $module, 'design' => $design] as $property => $value) {
            new \ReflectionProperty(FrontExtender::class, $property)->setValue($front, $value);
        }

        $front->getCartDeliveriesList([6 => (object)['id' => 6, 'module_id' => 81]], (object)['purchases' => [], 'total_price' => 0]);

        self::assertSame([], $assigned);
    }

    public function testOtherRoutesStillPrepareTheCart(): void
    {
        self::assertTrue(FrontExtender::preparesCart('cart'));
        self::assertTrue(FrontExtender::preparesCart('cart_ajax'));
        self::assertTrue(FrontExtender::preparesCart(null));
        self::assertFalse(FrontExtender::preparesCart('Sviat_Meest_cart_quote'));
    }
}
