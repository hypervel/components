<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Config\Repository;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemServiceProvider;
use Hypervel\Foundation\Application;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class FilesystemServiceProviderTest extends TestCase
{
    public function testReloadConfigurationRebuildsResolvedDisksFromCurrentConfiguration(): void
    {
        $application = new Application;
        $config = new Repository([
            'filesystems' => [
                'default' => 'first',
                'disks' => [
                    'first' => [
                        'driver' => 'local',
                        'root' => ParallelTesting::tempDir('FilesystemServiceProviderTest/first'),
                    ],
                    'second' => [
                        'driver' => 'local',
                        'root' => ParallelTesting::tempDir('FilesystemServiceProviderTest/second'),
                    ],
                ],
            ],
        ]);
        $application->instance('config', $config);
        $provider = new FilesystemServiceProvider($application);
        $provider->register();

        $manager = $application->make('filesystem');
        $disk = $application->make('filesystem.disk');

        $config->set('filesystems.default', 'second');
        $provider->reloadConfiguration();

        $refreshedDisk = $application->make('filesystem.disk');
        $this->assertSame($manager, $application->make(FilesystemManager::class));
        $this->assertNotSame($disk, $refreshedDisk);
        $this->assertSame($refreshedDisk, $manager->disk('second'));
    }
}
