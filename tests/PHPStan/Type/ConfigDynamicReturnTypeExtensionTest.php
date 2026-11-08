<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use PHPStan\Analyser\ResultCache\DirectoryResultCacheValueExtension;
use PHPStan\Analyser\ResultCache\FileResultCacheValueExtension;
use PHPStan\Analyser\ValueDependencyCollector;
use PHPStan\File\FileHelper;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function sort;
use function sprintf;

class ConfigDynamicReturnTypeExtensionTest extends TypeInferenceTestCase
{
    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(
        string $assertType,
        string $file,
        mixed ...$args,
    ): void {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-helper-function.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-repository-method.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-reproduction.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-facade-collection-method.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-repository-method-l12-20.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-provider-sources.php');
    }

    public function testConfigSourcesAreDependencies(): void
    {
        $collector = self::getContainer()->getByType(ValueDependencyCollector::class);
        $file = __DIR__ . '/data/config-file-dependency.php';
        $fileHelper = self::getContainer()->getByType(FileHelper::class);

        // Every config directory's candidate files and the directory below each key, and the framework files merged beneath them.
        $expected = [];

        foreach (['auth', 'test', 'missing'] as $name) {
            $expected[] = [FileResultCacheValueExtension::class, $fileHelper->normalizePath(LoadConfiguration::frameworkConfigPath() . '/' . $name . '.php')];
        }

        foreach (self::getContainer()->getByType(ConfigSourceResolver::class)->getConfigDirectories() as $configPath) {
            foreach (['auth', 'auth/defaults', 'test', 'test/foo', 'missing', 'missing/foo'] as $name) {
                $expected[] = [FileResultCacheValueExtension::class, $fileHelper->normalizePath($configPath . '/' . $name . '.php')];
            }

            foreach (['auth/defaults', 'test/foo', 'missing/foo'] as $name) {
                $expected[] = [
                    DirectoryResultCacheValueExtension::class,
                    DirectoryResultCacheValueExtension::createKey($fileHelper->normalizePath($configPath . '/' . $name), '*.php'),
                ];
            }
        }

        sort($expected);

        // The second run hits the parsed config cache and must still declare the sources.
        foreach ([1, 2] as $run) {
            $collector->startFile($file);
            self::processFile($file, static function (): void {
            });
            $dependencies = [];

            foreach ($collector->finishFile()['values'] as [$extensionClass, $key]) {
                $dependencies[] = [$extensionClass, $key];
            }

            sort($dependencies);

            $this->assertSame($expected, $dependencies, sprintf('Run %d', $run));
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/config-with-config-paths.neon'];
    }
}
