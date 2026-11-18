<?php

namespace Modules\Sviat\Meest\Init;

use Okay\Modules\Sviat\Meest\Init\Init;
use PHPUnit\Framework\TestCase;

/**
 * Init::init() виконується на кожен запит без try/catch: блок, чийого файла немає, кидає виняток у
 * DesignBlocks::registerBlock(), і кожна сторінка вітрини й адмінки відповідає 500.
 */
class RegisteredFilesTest extends TestCase
{
    private static function root(): string
    {
        return dirname((string)new \ReflectionClass(Init::class)->getFileName(), 2);
    }

    /** @return list<string> шляхи файлів, які Init.php реєструє; відносно каталогу модуля */
    public static function registeredFiles(string $source): array
    {
        $files = [];
        preg_match_all("/->addBackendBlock\\(\\s*'[^']+'\\s*,\\s*'([^']+)'/", $source, $backend);
        foreach ($backend[1] as $file) {
            $files[] = 'Backend/design/html/' . basename($file);
        }
        preg_match_all("/->registerBackendController\\(\\s*'([^']+)'/", $source, $controllers);
        foreach ($controllers[1] as $controller) {
            $files[] = 'Backend/Controllers/' . $controller . '.php';
        }

        return $files;
    }

    /** @return list<string> шаблони вітрини й модифікацій, які Init.php та module.json реєструють; відносно каталогу модуля */
    public static function frontFiles(string $init, string $moduleJson): array
    {
        $files = [];
        preg_match_all("/->addFrontBlock\\(\\s*'[^']+'\\s*,\\s*'([^']+)'/", $init, $front);
        foreach ($front[1] as $file) {
            $files[] = 'design/html/' . basename($file);
        }
        $json = json_decode($moduleJson, true);
        foreach ($json['modifications']['front'] ?? [] as $modification) {
            foreach ($modification['changes'] ?? [] as $change) {
                foreach (['append', 'appendBefore', 'prepend', 'replace'] as $kind) {
                    if (isset($change[$kind])) {
                        $files[] = 'design/html/' . basename((string)$change[$kind]);
                    }
                }
            }
        }

        return $files;
    }

    public function testModuleTemplatesFromModuleJsonExist(): void
    {
        $files = self::frontFiles((string)file_get_contents(self::root() . '/Init/Init.php'), (string)file_get_contents(self::root() . '/Init/module.json'));

        foreach (['design/html/cart_delivery.tpl', 'design/html/cart_validation.tpl', 'design/html/email_delivery.tpl', 'design/html/order_delivery.tpl'] as $expected) {
            self::assertContains($expected, $files);
        }
        self::assertSame([], array_values(array_filter($files, static fn(string $file): bool => !is_file(self::root() . '/' . $file))));
        self::assertContains('Backend/design/html/email_delivery.tpl', self::registeredFiles((string)file_get_contents(self::root() . '/Init/Init.php')));
    }

    /** Модифікація з якорем, якого в шаблоні немає, мовчки не застосовується: дія чи звіт просто зникають зі списку. */
    public function testBackendModificationsExistAndTheirAnchorsAreInCoreTemplates(): void
    {
        $json = json_decode((string)file_get_contents(self::root() . '/Init/module.json'), true);
        $backend = dirname(self::root(), 4) . '/backend/design/html/';
        $seen = [];
        foreach ($json['modifications']['backend'] ?? [] as $modification) {
            $core = (string)file_get_contents($backend . $modification['file']);
            foreach ($modification['changes'] as $change) {
                self::assertStringContainsString($change['find'], $core, $modification['file']);
                foreach (['append', 'appendBefore', 'appendAfter', 'prepend', 'replace'] as $kind) {
                    if (isset($change[$kind])) {
                        $seen[] = $change[$kind];
                        self::assertFileExists(self::root() . '/Backend/design/html/' . $change[$kind]);
                    }
                }
            }
        }

        self::assertSame(['orders_action_option.tpl', 'mass_result.tpl'], $seen);
    }

    public function testEveryRegisteredFileExists(): void
    {
        $files = self::registeredFiles((string)file_get_contents(self::root() . '/Init/Init.php'));

        self::assertContains('Backend/design/html/delivery_free_warning.tpl', $files, 'the parser sees the calls');
        $missing = array_values(array_filter($files, static fn(string $file): bool => !is_file(self::root() . '/' . $file)));
        self::assertSame([], $missing);
    }

    /** Головний контролер модуля — посилання зі списку модулів; без файла воно веде на 500. */
    public function testMainBackendControllerIsRegisteredAndExists(): void
    {
        $source = (string)file_get_contents(self::root() . '/Init/Init.php');
        self::assertSame(1, preg_match("/->setBackendMainController\\(\\s*'([^']+)'/", $source, $main));

        self::assertContains('Backend/Controllers/' . $main[1] . '.php', self::registeredFiles($source));
        self::assertFileExists(self::root() . '/Backend/Controllers/' . $main[1] . '.php');
    }
}
