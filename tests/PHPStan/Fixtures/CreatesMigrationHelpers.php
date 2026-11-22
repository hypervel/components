<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures;

use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\MigrationHelper;
use PHPStan\DependencyInjection\Container;
use PHPStan\File\FileHelper;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;

trait CreatesMigrationHelpers
{
    /**
     * Create a reader with fresh boot-derived migration and macro facts.
     *
     * @param list<string> $paths
     */
    private function createMigrationHelper(array $paths = [], bool $disableScan = false, ?Container $extensions = null, ?ConnectionConfigResolver $resolver = null): MigrationHelper
    {
        $container = self::getContainer();

        return new MigrationHelper(
            $container->getService('currentPhpVersionSimpleDirectParser'),
            $paths,
            $container->getByType(FileHelper::class),
            $disableScan,
            $container->getByType(ReflectionProvider::class),
            $container->getByType(InitializerExprTypeResolver::class),
            $resolver ?? $container->getByType(ConnectionConfigResolver::class),
            $extensions ?? $container,
        );
    }
}
