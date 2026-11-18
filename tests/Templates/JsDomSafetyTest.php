<?php

namespace Modules\Sviat\Meest\Templates;

use PHPUnit\Framework\TestCase;

/** S1: назви, адреси й тексти Meest потрапляють на сторінку лише через textContent/createElement. */
class JsDomSafetyTest extends TestCase
{
    public function testNoMarkupWritesInModuleScripts(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest';
        $files = [...(glob($root . '/design/js/*.js') ?: []), ...(glob($root . '/Backend/design/js/*.js') ?: [])];
        self::assertContains($root . '/design/js/meest_cart.js', $files);

        $offenders = [];
        foreach ($files as $file) {
            if (preg_match('/innerHTML|outerHTML|insertAdjacentHTML|\.html\(|document\.write/', (string)file_get_contents($file), $m) === 1) {
                $offenders[] = basename($file) . ': ' . $m[0];
            }
        }

        self::assertSame([], $offenders);
    }
}
