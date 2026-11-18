<?php

namespace Modules\Sviat\Meest\Init;

use PHPUnit\Framework\TestCase;

/**
 * Живі тести ходять у справжній Meest із ключем власника. Вони не повинні містити методів, що створюють,
 * друкують чи змінюють відправлення, і HTTP-дієслів запису: один такий рядок створить посилку за договором.
 */
class LiveTestsReadOnlyTest extends TestCase
{
    private const array WRITE_PREFIXES = ['parcel', 'print', 'register', 'tracking'];
    private const array WRITE_VERBS = ['DELETE', 'PUT'];

    public function testLiveTestsCallOnlyReadMethods(): void
    {
        $files = glob(dirname(__DIR__) . '/Live/*.php') ?: [];
        self::assertNotEmpty($files, 'there is at least one live test');

        $offenders = [];
        foreach ($files as $file) {
            foreach (self::stringLiterals((string)file_get_contents($file)) as $literal) {
                if (in_array($literal, self::WRITE_VERBS, true) || self::startsWithWritePrefix($literal)) {
                    $offenders[] = basename($file) . ': ' . $literal;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testDetectorSeesWriteLiterals(): void
    {
        $source = '<?php $a = \'parcelsList/01.01.2026\'; $b = "PUT"; $c = \'regionSearch\';';

        self::assertSame(['parcelsList/01.01.2026', 'PUT', 'regionSearch'], self::stringLiterals($source));
        self::assertTrue(self::startsWithWritePrefix('parcelsList/01.01.2026'));
        self::assertFalse(self::startsWithWritePrefix('regionSearch'));
    }

    /** @return list<string> */
    private static function stringLiterals(string $source): array
    {
        $literals = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = substr($token[1], 1, -1);
            }
        }

        return $literals;
    }

    private static function startsWithWritePrefix(string $literal): bool
    {
        foreach (self::WRITE_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($literal), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
