<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Properties\MigrationHelper;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\ResultCache\DirectoryResultCacheValueExtension;
use PHPStan\Analyser\ValueDependencyCollector;
use PHPStan\Collectors\Registry as CollectorRegistry;
use PHPStan\File\FileHelper;
use PHPStan\Rules\DirectRegistry as DirectRuleRegistry;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

class ModelPropertyTypeNodeResolverExtensionTest extends PHPStanTestCase
{
    #[Test]
    public function itTracksTheSchemaDirectoriesForCallersOfAMethodWithAModelPropertyParameter(): void
    {
        $container = self::getContainer();
        $collector = $container->getByType(ValueDependencyCollector::class);
        $file = $container->getByType(FileHelper::class)->normalizePath(__DIR__ . '/data/model-property-schema-dependency.php');

        $result = $container->getByType(FileAnalyser::class)->analyseFile(
            $file,
            [$file => true],
            new DirectRuleRegistry([]),
            new CollectorRegistry([]),
            null,
        );

        self::assertSame([], $result->getErrors());

        $migrationPaths = $container->getByType(MigrationHelper::class)->getMigrationPaths();

        // The workbench migrations a provider loads and the application's migrations.
        self::assertCount(2, $migrationPaths);

        foreach ($migrationPaths as $directory => $pattern) {
            self::assertSame('*_*.php', $pattern);
            self::assertContains(
                ValueDependencyCollector::getId(DirectoryResultCacheValueExtension::class, $collector->getDirectoryKey($directory, $pattern)),
                $result->getValueDependencies()['dependents'][$file]['analysis'],
            );
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Type/data/config-check-model-properties.neon'];
    }
}
