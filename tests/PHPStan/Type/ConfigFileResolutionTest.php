<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use PHPStan\Analyser\ResultCache\DirectoryResultCacheValueExtension;
use PHPStan\Analyser\ResultCache\FileResultCacheValueExtension;
use PHPStan\Analyser\ValueDependencyCollector;
use PHPStan\File\FileHelper;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_filter;
use function sprintf;

class ConfigFileResolutionTest extends TypeInferenceTestCase
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
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-file-resolution.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/config-file-scope.php');
    }

    public function testEveryFileThatCanHoldAKeyIsAFileDependency(): void
    {
        $collector = self::getContainer()->getByType(ValueDependencyCollector::class);
        $file = __DIR__ . '/data/config-file-resolution.php';

        $collector->startFile($file);
        self::processFile($file, static function (): void {
        });
        $dependencyFiles = array_column(array_filter(
            $collector->finishFile()['values'],
            static fn (array $value): bool => $value[0] === FileResultCacheValueExtension::class,
        ), 1);

        $fileHelper = self::getContainer()->getByType(FileHelper::class);

        // config('nested.rcnest.v') can come from any prefix of the key in any config directory
        foreach (['config', 'modules/a/config', 'modules/b/config', 'literal[1]/config'] as $configDirectory) {
            foreach (['nested.php', 'nested/rcnest.php', 'nested/rcnest/v.php'] as $configFile) {
                $this->assertContains(
                    $fileHelper->normalizePath(__DIR__ . '/data/config-file-resolution/' . $configDirectory . '/' . $configFile),
                    $dependencyFiles,
                );
            }
        }

        foreach ($dependencyFiles as $dependencyFile) {
            $this->assertStringNotContainsString('*', $dependencyFile);
        }
    }

    public function testConfigSourcesReadByAConfigFileAreDependencies(): void
    {
        $collector = self::getContainer()->getByType(ValueDependencyCollector::class);
        $file = __DIR__ . '/data/config-file-scope-dependency.php';
        $fileHelper = self::getContainer()->getByType(FileHelper::class);
        $leaf = $fileHelper->normalizePath(__DIR__ . '/data/config-file-resolution/config/scopeleaf.php');
        // A file created below the key the config file reads changes that key's value.
        $leafDirectory = DirectoryResultCacheValueExtension::createKey(
            $fileHelper->normalizePath(__DIR__ . '/data/config-file-resolution/config/scopeleaf/v'),
            '*.php',
        );

        // The second run reads the parsed config cache and must still declare the sources the config file reads.
        foreach ([1, 2] as $run) {
            $collector->startFile($file);
            self::processFile($file, static function (): void {
            });
            $dependencies = array_column($collector->finishFile()['values'], 1);

            $this->assertContains($leaf, $dependencies, sprintf('Run %d', $run));
            $this->assertContains($leafDirectory, $dependencies, sprintf('Run %d', $run));
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/config-file-resolution.neon'];
    }
}
