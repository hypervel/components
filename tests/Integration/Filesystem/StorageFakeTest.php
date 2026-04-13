<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Filesystem;

use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class StorageFakeTest extends TestCase
{
    public function testFakePreservesOriginalDiskThrowConfig()
    {
        config(['filesystems.disks.local.throw' => true]);

        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertTrue($fake->getConfig()['throw']);
    }

    public function testFakeDefaultsThrowToFalseWhenNotConfigured()
    {
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app')]]);

        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertFalse($fake->getConfig()['throw']);
    }

    public function testFakeRegistersTemporaryUploadUrlBuilder()
    {
        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertTrue($fake->providesTemporaryUrls());
        $this->assertTrue($fake->providesTemporaryUploadUrls());
    }

    public function testFakeTemporaryUploadUrlReturnsArrayWithUrlAndHeaders()
    {
        $fake = Storage::fake('local');

        /** @var FilesystemAdapter $fake */
        $result = $fake->temporaryUploadUrl('test.txt', now()->addMinutes(1));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('headers', $result);
    }

    public function testFakeUsesParallelTestingTokenSuffix()
    {
        ParallelTesting::resolveTokenUsing(fn () => '42');

        try {
            $fake = Storage::fake('local');

            /** @var FilesystemAdapter $fake */
            $root = $fake->getConfig()['root'];

            $this->assertStringEndsWith('_test_42', $root);
        } finally {
            ParallelTesting::resolveTokenUsing(null);
        }
    }

    public function testPersistentFakePreservesOriginalDiskThrowConfig()
    {
        config(['filesystems.disks.local.throw' => true]);

        $fake = Storage::persistentFake('local');

        /** @var FilesystemAdapter $fake */
        $this->assertTrue($fake->getConfig()['throw']);
    }
}
