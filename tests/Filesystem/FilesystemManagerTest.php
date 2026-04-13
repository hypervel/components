<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

enum FilesystemTestStringBackedDisk: string
{
    case Local = 'local';
}

enum FilesystemTestIntBackedDisk: int
{
    case Local = 1;
}

enum FilesystemTestUnitDisk
{
    case local;
}

/**
 * @internal
 * @coversNothing
 */
class FilesystemManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('FilesystemManager');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $filesystem = new \League\Flysystem\Filesystem(
                new \League\Flysystem\Local\LocalFilesystemAdapter(dirname($this->tempDir))
            );
            $filesystem->deleteDirectory(basename($this->tempDir));
        }

        parent::tearDown();
    }

    public function testExceptionThrownOnUnsupportedDriver()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Disk [local] does not have a configured driver.');

        $container = $this->getContainer([
            'disks' => [
                'local' => [],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $filesystem->disk('local');
    }

    public function testCanBuildOnDemandDisk()
    {
        $filesystem = new FilesystemManager($this->getContainer());

        $this->assertInstanceOf(Filesystem::class, $filesystem->build('my-custom-path'));

        $this->assertInstanceOf(Filesystem::class, $filesystem->build([
            'driver' => 'local',
            'root' => 'my-custom-path',
            'url' => 'my-custom-url',
            'visibility' => 'public',
        ]));

        rmdir(__DIR__ . '/../../my-custom-path');
    }

    public function testCanBuildReadOnlyDisks()
    {
        $filesystem = new FilesystemManager($this->getContainer());

        $disk = $filesystem->build([
            'driver' => 'local',
            'read-only' => true,
            'root' => 'my-custom-path',
            'url' => 'my-custom-url',
            'visibility' => 'public',
        ]);

        file_put_contents(__DIR__ . '/../../my-custom-path/path.txt', 'contents');

        // read operations work
        $this->assertEquals('contents', $disk->get('path.txt'));
        $this->assertEquals(['path.txt'], $disk->files());

        // write operations fail
        $this->assertFalse($disk->put('path.txt', 'contents'));
        $this->assertFalse($disk->delete('path.txt'));
        $this->assertFalse($disk->deleteDirectory('directory'));
        $this->assertFalse($disk->prepend('path.txt', 'data'));
        $this->assertFalse($disk->append('path.txt', 'data'));
        $handle = fopen('php://memory', 'rw');
        fwrite($handle, 'content');
        $this->assertFalse($disk->writeStream('path.txt', $handle));
        fclose($handle);

        unlink(__DIR__ . '/../../my-custom-path/path.txt');
        rmdir(__DIR__ . '/../../my-custom-path');
    }

    public function testCanBuildScopedDisks()
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'to-be-scoped',
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $local = $filesystem->disk('local');
            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'local',
                'prefix' => 'path-prefix',
            ]);

            $scoped->put('dirname/filename.txt', 'file content');
            $this->assertEquals('file content', $local->get('path-prefix/dirname/filename.txt'));
            $local->deleteDirectory('path-prefix');
        } finally {
            rmdir(__DIR__ . '/../../to-be-scoped');
        }
    }

    public function testCanBuildScopedDiskFromScopedDisk()
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'root-to-be-scoped',
                    ],
                    'scoped-from-root' => [
                        'driver' => 'scoped',
                        'disk' => 'local',
                        'prefix' => 'scoped-from-root-prefix',
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $root = $filesystem->disk('local');
            $nestedScoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'scoped-from-root',
                'prefix' => 'nested-scoped-prefix',
            ]);

            $nestedScoped->put('dirname/filename.txt', 'file content');
            $this->assertEquals('file content', $root->get('scoped-from-root-prefix/nested-scoped-prefix/dirname/filename.txt'));
            $root->deleteDirectory('scoped-from-root-prefix');
        } finally {
            rmdir(__DIR__ . '/../../root-to-be-scoped');
        }
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testCanBuildScopedDisksWithVisibility()
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'to-be-scoped',
                        'visibility' => 'public',
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'local',
                'prefix' => 'path-prefix',
                'visibility' => 'private',
            ]);

            $scoped->put('dirname/filename.txt', 'file content');

            $this->assertEquals('private', $scoped->getVisibility('dirname/filename.txt'));
        } finally {
            unlink(__DIR__ . '/../../to-be-scoped/path-prefix/dirname/filename.txt');
            rmdir(__DIR__ . '/../../to-be-scoped/path-prefix/dirname');
            rmdir(__DIR__ . '/../../to-be-scoped/path-prefix');
            rmdir(__DIR__ . '/../../to-be-scoped');
        }
    }

    public function testCanBuildScopedDisksWithThrow()
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'to-be-scoped',
                        'throw' => false,
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'local',
                'prefix' => 'path-prefix',
                'throw' => true,
            ]);

            $this->expectException(UnableToReadFile::class);
            $scoped->get('dirname/filename.txt');
        } finally {
            rmdir(__DIR__ . '/../../to-be-scoped');
        }
    }

    public function testCanBuildInlineScopedDisks()
    {
        try {
            $filesystem = new FilesystemManager($this->getContainer());

            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => [
                    'driver' => 'local',
                    'root' => 'to-be-scoped',
                ],
                'prefix' => 'path-prefix',
            ]);

            $scoped->put('dirname/filename.txt', 'file content');
            $this->assertTrue(is_dir(__DIR__ . '/../../to-be-scoped/path-prefix'));
            $this->assertEquals(file_get_contents(__DIR__ . '/../../to-be-scoped/path-prefix/dirname/filename.txt'), 'file content');
        } finally {
            unlink(__DIR__ . '/../../to-be-scoped/path-prefix/dirname/filename.txt');
            rmdir(__DIR__ . '/../../to-be-scoped/path-prefix/dirname');
            rmdir(__DIR__ . '/../../to-be-scoped/path-prefix');
            rmdir(__DIR__ . '/../../to-be-scoped');
        }
    }

    public function testCustomDriverClosureBoundObjectIsFilesystemManager()
    {
        $container = $this->getContainer([
            'disks' => [
                __CLASS__ => [
                    'driver' => __CLASS__,
                ],
            ],
        ]);
        $manager = new FilesystemManager($container);
        $manager->extend(__CLASS__, fn () => $this);
        $this->assertSame($manager, $manager->disk(__CLASS__));
    }

    public function testPoolableDriver()
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                ],
            ],
        ]);
        $filesystem = (new FilesystemManager($container))
            ->addPoolable('local');

        Container::setInstance($container);

        $this->assertInstanceOf(FilesystemPoolProxy::class, $filesystem->disk('local'));
    }

    public function testDiskAcceptsStringBackedEnum(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $disk = $filesystem->disk(FilesystemTestStringBackedDisk::Local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    public function testDiskAcceptsUnitEnum(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $disk = $filesystem->disk(FilesystemTestUnitDisk::local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    public function testDiskWithIntBackedEnumResolvesAsString(): void
    {
        $container = $this->getContainer([
            'disks' => [
                '1' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        // Int-backed enum value is cast to string for disk resolution
        $disk = $filesystem->disk(FilesystemTestIntBackedDisk::Local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    public function testDriveAcceptsStringBackedEnum(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $disk = $filesystem->drive(FilesystemTestStringBackedDisk::Local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    protected function getContainer(array $config = []): Container
    {
        $config = new Repository(['filesystems' => $config]);

        $container = new Container;
        $container->instance('config', $config);
        $container->instance(ContainerContract::class, $container);
        $container->singleton(PoolFactory::class, PoolManager::class);

        return $container;
    }
}
