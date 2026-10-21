<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;
use League\Flysystem\UnableToReadFile;

class StorageFacadeTest extends TestCase
{
    /**
     * Configure an isolated storage directory.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->useStoragePath(ParallelTesting::tempDir('StorageFacadeTest'));

        (new Filesystem)->deleteDirectory($app->storagePath());
    }

    /**
     * Remove the storage directory.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->app->storagePath());

        parent::tearDown();
    }

    public function testFakeWhenDiskNotConfiguredDoesNotThrowExceptionOnError(): void
    {
        $result = Storage::fake('test')->get('nonExistentFile');

        $this->assertNull($result);
    }

    public function testFakeWhenThrowSetToDiskThrowsExceptionOnError(): void
    {
        config(['filesystems.disks.test' => ['throw' => true]]);

        $this->expectException(UnableToReadFile::class);
        Storage::fake('test')->get('nonExistentFile');
    }

    public function testFakeWhenThrowOverwrittenUsesOverwrite(): void
    {
        config(['filesystems.disks.test' => ['throw' => true]]);

        $result = Storage::fake('test', ['throw' => false])->get('nonExistentFile');
        $this->assertNull($result);
    }

    public function testPersistentFakeWhenDiskNotConfiguredDoesNotThrowExceptionOnError(): void
    {
        $result = Storage::persistentFake('test')->get('nonExistentFile');

        $this->assertNull($result);
    }

    public function testPersistentFakeWhenThrowSetToDiskThrowsExceptionOnError(): void
    {
        config(['filesystems.disks.test' => ['throw' => true]]);

        $this->expectException(UnableToReadFile::class);
        Storage::persistentFake('test')->get('nonExistentFile');
    }

    public function testPersistentFakeWhenThrowOverwrittenUsesOverwrite(): void
    {
        config(['filesystems.disks.test' => ['throw' => true]]);

        $result = Storage::persistentFake('test', ['throw' => false])->get('nonExistentFile');
        $this->assertNull($result);
    }

    public function testStorageFakeMethodsWithEnums(): void
    {
        $this->assertNull(Storage::persistentFake(StorageFakeStringDisk::Test)->get('nonExistentFile'));
        $this->assertNull(Storage::fake(StorageFakeStringDisk::Public)->get('nonExistentFile'));
    }

    public function testCanFakeOnDemandDisk(): void
    {
        $fake = Storage::fake('ondemand');

        Storage::build([
            'driver' => 'ftp',
            'host' => 'example.com',
            'username' => 'foo',
            'password' => 'bar',
        ])->put('file.txt', 'contents');

        $fake->assertExists('file.txt');
    }

    public function testFakingOnDemandDiskDoesNotAffectScopedDisks(): void
    {
        config([
            'filesystems.disks.photos' => ['driver' => 'local', 'root' => $root = storage_path('photos')],
            'filesystems.disks.avatars' => ['driver' => 'scoped', 'disk' => 'photos', 'prefix' => 'avatars'],
        ]);

        $fake = Storage::fake('ondemand');

        Storage::disk('avatars')->put('file.txt', 'contents');

        $fake->assertMissing('file.txt');
        $this->assertFileExists($root . '/avatars/file.txt');
    }

    public function testFakingOnDemandDiskDoesNotAffectReadThroughDisks(): void
    {
        config([
            'filesystems.disks.assets' => [
                'driver' => 'read-through',
                'primary' => ['driver' => 'local', 'root' => $primaryRoot = storage_path('primary')],
                'fallback' => ['driver' => 'local', 'root' => $fallbackRoot = storage_path('fallback')],
            ],
        ]);

        (new Filesystem)->ensureDirectoryExists($fallbackRoot);
        file_put_contents($fallbackRoot . '/fallback.txt', 'fallback contents');

        $fake = Storage::fake('ondemand');

        Storage::disk('assets')->put('file.txt', 'contents');

        $this->assertSame('fallback contents', Storage::disk('assets')->get('fallback.txt'));
        $fake->assertMissing('file.txt');
        $this->assertFileExists($primaryRoot . '/file.txt');
    }

    public function testOnDemandFakesPreserveNamedBuildsAndCanBeCleared(): void
    {
        $root = storage_path('on-demand');
        $config = ['driver' => 'local', 'root' => $root];
        $fake = Storage::persistentFake('ondemand');

        $this->assertSame($fake, Storage::build($config));
        $this->assertNotSame($fake, Storage::build($config, 'uploads'));

        Storage::forgetDisk('ondemand');
        Storage::build($config)->put('after-forget.txt', 'contents');
        $this->assertFileExists($root . '/after-forget.txt');
        $fake->assertMissing('after-forget.txt');

        $fake = Storage::fake('ondemand');
        $this->assertSame($fake, Storage::build($root));

        Storage::purge('ondemand');
        Storage::build($root)->put('after-purge.txt', 'contents');
        $this->assertFileExists($root . '/after-purge.txt');
        $fake->assertMissing('after-purge.txt');
    }

    public function testFakePreservesOriginalDiskThrowConfig(): void
    {
        config(['filesystems.disks.local.throw' => true]);

        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertTrue($fake->getConfig()['throw']);
    }

    public function testFakeDefaultsThrowToFalseWhenNotConfigured(): void
    {
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app')]]);

        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertFalse($fake->getConfig()['throw']);
    }

    public function testFakeRegistersTemporaryUploadUrlBuilder(): void
    {
        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertTrue($fake->providesTemporaryUrls());
        $this->assertTrue($fake->providesTemporaryUploadUrls());
    }

    public function testFakeTemporaryUploadUrlReturnsArrayWithUrlAndHeaders(): void
    {
        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $result = $fake->temporaryUploadUrl('test.txt', now()->addMinutes(1));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('headers', $result);
    }

    public function testFakeUsesParallelTestingTokenSuffix(): void
    {
        ParallelTesting::resolveTokenUsing(fn (): string => '42');

        try {
            $fake = Storage::fake('local');

            /** @var FilesystemAdapter $fake */
            $root = $fake->getConfig()['root'];

            $this->assertStringEndsWith('_test_42', $root);
        } finally {
            ParallelTesting::resolveTokenUsing(null);
        }
    }

    public function testPersistentFakePreservesOriginalDiskThrowConfig(): void
    {
        config(['filesystems.disks.local.throw' => true]);

        $fake = Storage::persistentFake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertTrue($fake->getConfig()['throw']);
    }

    public function testIntegerBackedEnumDiskZeroDoesNotSelectTheDefaultDisk(): void
    {
        config([
            'filesystems.default' => 'local',
            'filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app')],
            'filesystems.disks.0' => ['driver' => 'local', 'root' => storage_path('zero'), 'throw' => true],
        ]);

        $this->assertSame(Storage::disk(), Storage::disk(''));
        $this->assertNotSame(Storage::disk(), Storage::disk(StorageFakeDisk::Zero));

        $fake = Storage::fake(StorageFakeDisk::Zero);
        $persistentFake = Storage::persistentFake(StorageFakeDisk::Zero);
        $defaultFake = Storage::fake('');
        $testingSuffix = ($token = ParallelTesting::token()) ? "_test_{$token}" : '';

        /** @var FilesystemAdapter $fake */
        $this->assertSame(storage_path('framework/testing/disks/0' . $testingSuffix), $fake->getConfig()['root']);
        $this->assertTrue($fake->getConfig()['throw']);

        /** @var FilesystemAdapter $persistentFake */
        $this->assertSame(storage_path('framework/testing/disks/0'), $persistentFake->getConfig()['root']);
        $this->assertTrue($persistentFake->getConfig()['throw']);

        /** @var FilesystemAdapter $defaultFake */
        $this->assertSame(storage_path('framework/testing/disks/local' . $testingSuffix), $defaultFake->getConfig()['root']);
    }
}

enum StorageFakeDisk: int
{
    case Zero = 0;
}

enum StorageFakeStringDisk: string
{
    case Test = 'test';
    case Public = 'public';
}
