<?php

namespace Modules\Sviat\Meest\Parcels;

use PHPUnit\Framework\TestCase;

/** Адмінка залежить від посилок, а не навпаки: інакше зміна сторінки налаштувань ламає створення посилок. */
class LayeringTest extends TestCase
{
    public function testParcelsDoNotUseBackendClasses(): void
    {
        $files = glob(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Parcels/*.php') ?: [];
        self::assertNotEmpty($files);
        $found = [];
        foreach ($files as $file) {
            preg_match_all('/^use (Okay\\\\(?:Admin|Modules\\\\Sviat\\\\Meest\\\\Backend)\\\\[\w\\\\]+);/m', (string)file_get_contents($file), $uses);
            foreach ($uses[1] as $class) {
                $found[] = basename($file) . ': ' . $class;
            }
        }

        self::assertSame([], $found);
    }
}
