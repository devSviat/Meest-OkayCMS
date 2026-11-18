<?php

namespace Modules\Sviat\Meest\Extenders;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Module;
use Okay\Modules\Sviat\Meest\Cart\DictionaryReady;
use Okay\Modules\Sviat\Meest\Extenders\FrontExtender;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use PHPUnit\Framework\TestCase;

/** Ціна з кешу сесії ставиться лише на спосіб цього модуля; інші способи кошика лишаються такими, як їх дало ядро. */
class ApplyQuotesTest extends TestCase
{
    private const int MODULE = 81;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function front(): FrontExtender
    {
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);
        $quotes = new \ReflectionClass(CartQuotes::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(CartQuotes::class, 'module')->setValue($quotes, $module);
        $dictionary = new DictionaryReady(new InMemoryState(), $this->createStub(EntityFactory::class));
        new \ReflectionProperty(DictionaryReady::class, 'ready')->setValue($dictionary, true);
        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willReturnArgument(0);

        $front = new \ReflectionClass(FrontExtender::class)->newInstanceWithoutConstructor();
        foreach (['quotes' => $quotes, 'dictionary' => $dictionary, 'translations' => $translations] as $property => $value) {
            new \ReflectionProperty(FrontExtender::class, $property)->setValue($front, $value);
        }

        return $front;
    }

    private static function delivery(int $id, int $moduleId): object
    {
        return (object)['id' => $id, 'module_id' => $moduleId, 'paid' => 1, 'free_from' => 1_000_000, 'price' => 70.0, 'is_free_delivery' => false, 'delivery_price_text' => '70 грн', 'total_price_with_delivery' => 570.0];
    }

    public function testOtherDeliveriesUntouched(): void
    {
        $foreign = self::delivery(4, 12);
        $before = clone $foreign;
        $own = self::delivery(6, self::MODULE);

        $this->front()->applyQuotes([4 => $foreign, 6 => $own], (object)['total_price' => 500.0, 'purchases' => []]);

        self::assertEquals($before, $foreign);
        self::assertSame(0.0, $own->price, 'control: the own method waits for a point and adds nothing');
        self::assertSame('sviat__meest__cart_price_after_point', $own->delivery_price_text);
    }
}
