<?php

namespace Modules\Sviat\Meest\Init;

use Okay\Core\OkayContainer\Reference\ServiceReference;
use Okay\Modules\Sviat\Meest\Init\Init;
use PHPUnit\Framework\TestCase;

/** Контейнер передає аргументи за позицією: визначення, що розійшлося з конструктором, падає лише під час виконання. */
class ServicesTest extends TestCase
{
    public function testEveryDefinitionMatchesItsConstructor(): void
    {
        $services = require dirname((new \ReflectionClass(Init::class))->getFileName()) . '/services.php';
        self::assertNotEmpty($services);

        foreach ($services as $name => $definition) {
            self::assertTrue(is_a($definition['class'], $name, true), "{$name} is not implemented by {$definition['class']}");
            $params = new \ReflectionClass($definition['class'])->getConstructor()?->getParameters() ?? [];
            $arguments = $definition['arguments'] ?? [];
            $required = count(array_filter($params, static fn(\ReflectionParameter $p): bool => !$p->isOptional()));
            self::assertGreaterThanOrEqual($required, count($arguments), $name);
            self::assertLessThanOrEqual(count($params), count($arguments), $name);
            foreach ($arguments as $i => $argument) {
                $type = $params[$i]->getType();
                if ($argument instanceof ServiceReference && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    self::assertTrue(is_a($argument->getName(), $type->getName(), true), "{$name} #{$i}: {$argument->getName()} is not {$type->getName()}");
                }
            }
        }
    }

    /** Кошик отримує ці класи через DI за type-hint; без визначення контейнер не знає аргументів-замикань. */
    public function testCartServicesAreRegistered(): void
    {
        $services = require dirname((new \ReflectionClass(Init::class))->getFileName()) . '/services.php';

        foreach ([
            \Okay\Modules\Sviat\Meest\Cart\CitySearch::class,
            \Okay\Modules\Sviat\Meest\Cart\BranchList::class,
            \Okay\Modules\Sviat\Meest\Cart\UahTotal::class,
            \Okay\Modules\Sviat\Meest\Cart\DictionaryReady::class,
            \Okay\Modules\Sviat\Meest\Quote\QuoteService::class,
            \Okay\Modules\Sviat\Meest\Quote\CartQuotes::class,
            \Okay\Modules\Sviat\Meest\Quote\ClientAddress::class,
        ] as $class) {
            self::assertArrayHasKey($class, $services, $class);
        }
    }

    public function testIntegrationFacadeIsRegistered(): void
    {
        $services = require dirname((new \ReflectionClass(Init::class))->getFileName()) . '/services.php';

        self::assertArrayHasKey(\Okay\Modules\Sviat\Meest\Integration\MeestOrders::class, $services);
    }
}
