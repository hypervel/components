<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\PHPStan\RuntimeInputsResultCacheValueExtension;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\PHPStan\Support\RuntimeInputs;
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
        $runtimeInputs = self::getContainer()->getByType(RuntimeInputs::class);

        // Every config directory's candidate files and the directory below each key, and the framework files
        // merged beneath them. A path in Testbench's runtime copy is tracked through its unchanged skeleton source.
        $expected = [];

        foreach (['auth', 'test', 'missing'] as $name) {
            $expected[] = [FileResultCacheValueExtension::class, $fileHelper->normalizePath(LoadConfiguration::frameworkConfigPath() . '/' . $name . '.php')];
        }

        foreach (self::getContainer()->getByType(ConfigSourceResolver::class)->getConfigDirectories() as $configPath) {
            foreach (['auth', 'auth/defaults', 'test', 'test/foo', 'missing', 'missing/foo'] as $name) {
                $expected[] = [FileResultCacheValueExtension::class, $runtimeInputs->sourcePath($fileHelper->normalizePath($configPath . '/' . $name . '.php'))];
            }

            foreach (['auth/defaults', 'test/foo', 'missing/foo'] as $name) {
                $expected[] = [
                    DirectoryResultCacheValueExtension::class,
                    DirectoryResultCacheValueExtension::createKey($runtimeInputs->sourceDirectory($fileHelper->normalizePath($configPath . '/' . $name), '*.php'), '*.php'),
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
            $bootDependencies = 0;

            foreach ($collector->finishFile()['values'] as [$extensionClass, $key]) {
                // The booted providers decide the config directories, merges and writes.
                if ($extensionClass === RuntimeInputsResultCacheValueExtension::class) {
                    ++$bootDependencies;

                    continue;
                }

                $dependencies[] = [$extensionClass, $key];
            }

            sort($dependencies);

            $this->assertSame($expected, $dependencies, sprintf('Run %d', $run));
            $this->assertSame(1, $bootDependencies, sprintf('Run %d', $run));
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/config-with-config-paths.neon'];
    }
}
