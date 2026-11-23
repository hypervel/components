<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Support;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\RuntimeInputsResultCacheValueExtension;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\PHPStan\Support\EnvironmentVariableScanner;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Testing\ParallelTesting;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;

use function array_pop;
use function file_put_contents;
use function mkdir;
use function symlink;
use function unlink;
use function view;

class RuntimeInputsTest extends PHPStanTestCase
{
    public function testLinkedViewRootsAreTrackedThroughTheLink(): void
    {
        $root = ParallelTesting::tempDir('PHPStanRuntimeInputs');
        (new Filesystem)->deleteDirectory($root);
        mkdir($root . '/old', 0777, true);
        mkdir($root . '/new');
        file_put_contents($root . '/old/panel.blade.php', 'Old');
        file_put_contents($root . '/new/panel.blade.php', 'New');
        file_put_contents($root . '/new/extra.blade.php', 'Extra');
        symlink($root . '/old', $root . '/views');

        $keys = [];
        $scope = $this->createStubForIntersectionOfInterfaces([Scope::class, DependencyTracker::class]);
        $scope->method('trackValueDependency')->willReturnCallback(static function (string $extension, string $key) use (&$keys): void {
            $keys[] = $key;
        });

        $container = self::getContainer();
        $finder = view()->getFinder();
        $paths = $finder->getPaths();
        $finder->setPaths([$root . '/views']);

        try {
            // A fresh instance, since the container's has already built its views key.
            $runtimeInputs = new RuntimeInputs(
                $container->getByType(ReflectionProvider::class),
                $container->getByType(DeclarationDependencyTracker::class),
                $container->getByType(EnvironmentVariableScanner::class),
                $container->getByType(ConfigSourceResolver::class),
                $container->getByType(RuntimeInputsResultCacheValueExtension::class),
                $container->getParameter('tmpDir'),
            );

            $runtimeInputs->trackLinkedDirectories($scope, [$root . '/views'], '*.blade.php');
            $contentKey = array_pop($keys);

            $runtimeInputs->trackViews($scope);
            $viewsKey = array_pop($keys);

            // A later run computes the stored keys' values in a new process.
            $values = static fn (): array => [
                (new RuntimeInputsResultCacheValueExtension($container->getParameter('currentWorkingDirectory')))->getValue($contentKey),
                (new RuntimeInputsResultCacheValueExtension($container->getParameter('currentWorkingDirectory')))->getValue($viewsKey),
            ];

            [$content, $views] = $values();

            // The old target is unchanged, so only the link's own path shows the new contents and names.
            unlink($root . '/views');
            symlink($root . '/new', $root . '/views');

            [$retargetedContent, $retargetedViews] = $values();

            $this->assertNotSame($content, $retargetedContent);
            $this->assertNotSame($views, $retargetedViews);
        } finally {
            $finder->setPaths($paths);
            $finder->flush();
            (new Filesystem)->deleteDirectory($root);
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
