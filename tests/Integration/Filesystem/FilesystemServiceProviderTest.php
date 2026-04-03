<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Filesystem;

use Hypervel\Filesystem\FilesystemServiceProvider;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class FilesystemServiceProviderTest extends TestCase
{
    public function testItThrowsWhenServedDisksHaveConflictingUris(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [other] disk conflicts with the [local] disk at [/storage]. Each served disk must have a unique URL.');

        config(['filesystems.disks' => [
            'local' => [
                'driver' => 'local',
                'root' => storage_path('app'),
                'serve' => true,
            ],
            'other' => [
                'driver' => 'local',
                'root' => storage_path('other'),
                'serve' => true,
            ],
        ]]);

        (new FilesystemServiceProvider($this->app))->boot();
    }

    public function testServedDisksWithUniqueUrlsDoNotConflict(): void
    {
        config(['filesystems.disks' => [
            'local' => [
                'driver' => 'local',
                'root' => storage_path('app'),
                'serve' => true,
                'url' => '/storage',
            ],
            'other' => [
                'driver' => 'local',
                'root' => storage_path('other'),
                'serve' => true,
                'url' => '/other',
            ],
        ]]);

        (new FilesystemServiceProvider($this->app))->boot();

        $this->assertCount(2, $this->app->make('config')->get('filesystems.disks'));
    }

    public function testServedDiskWithUrlWithoutPathDoesNotError(): void
    {
        config(['filesystems.disks' => [
            'local' => [
                'driver' => 'local',
                'root' => storage_path('app'),
                'serve' => true,
                'url' => 'https://example.com',
            ],
        ]]);

        (new FilesystemServiceProvider($this->app))->boot();

        $this->assertCount(1, $this->app->make('config')->get('filesystems.disks'));
    }
}
