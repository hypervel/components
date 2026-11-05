<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Methods;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\PHPStan\Methods\StorageMethodsClassReflectionExtension;
use Hypervel\PHPStan\ReturnTypes\FilesystemDiskHelper;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\PHPStan\Fixtures\Filesystem\ForwardingFilesystemManager;
use Hypervel\Tests\PHPStan\Fixtures\Filesystem\SelectingFilesystemManager;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;

class StorageMethodsClassReflectionExtensionTest extends PHPStanTestCase
{
    /**
     * @param class-string<FilesystemManager> $managerClass
     */
    #[DataProvider('managerProvider')]
    public function testFacadeCallsForwardToTheDefaultDiskOnlyThroughTheStockManager(string $managerClass, string $returnType): void
    {
        $app = new Container;
        $app->instance('config', new Repository(['filesystems' => [
            'default' => 's3',
            'disks' => ['s3' => ['driver' => 's3']],
        ]]));
        $app->instance('filesystem', new $managerClass($app));

        $reflectionProvider = $this->createReflectionProvider();
        $diskHelper = new FilesystemDiskHelper($reflectionProvider, self::getContainer()->getByType(ClosureTypeFactory::class));
        $diskHelper->setContainer($app);

        $method = (new StorageMethodsClassReflectionExtension($reflectionProvider, $diskHelper))
            ->getMethod($reflectionProvider->getClass(Storage::class), 'assertExists');

        $this->assertSame($returnType, $method->getVariants()[0]->getReturnType()->describe(VerbosityLevel::precise()));
    }

    /** @return iterable<string, array{class-string<FilesystemManager>, string}> */
    public static function managerProvider(): iterable
    {
        // The pooled default disk's own assertExists() returns that disk.
        yield 'stock manager' => [FilesystemManager::class, 'Hypervel\Filesystem\ClientPooledFilesystem'];

        // A manager whose disk() or __call() is overridden may not reach the default disk, so the
        // manager's declared mixins apply.
        yield 'overridden disk()' => [SelectingFilesystemManager::class, 'Hypervel\Filesystem\FilesystemAdapter'];
        yield 'overridden __call()' => [ForwardingFilesystemManager::class, 'Hypervel\Filesystem\FilesystemAdapter'];
    }
}
