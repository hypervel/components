<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\FilesystemDisksAreReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class FilesystemDisksAreReachableTest extends TestCase
{
    private string $root;

    /**
     * Create an isolated filesystem root.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = ParallelTesting::tempDir('DoctorFilesystemDisk');
        File::deleteDirectory($this->root);
        File::makeDirectory($this->root, 0775, true);
    }

    /**
     * Remove the isolated root.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->root);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenTheDefaultLocalFilesystemDiskIsReachable(): void
    {
        config([
            'filesystems.default' => 'doctor',
            'filesystems.disks' => [
                'doctor' => [
                    'driver' => 'local',
                    'root' => $this->root,
                ],
                's3' => [
                    'driver' => 's3',
                    'key' => 'unused',
                    'secret' => 'unused',
                    'region' => 'us-east-1',
                    'bucket' => 'unused',
                ],
            ],
        ]);

        $result = (new FilesystemDisksAreReachable)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('The application can reach the default filesystem disk.', $result->summary);
    }

    public function testReportsAnUndefinedDefaultDisk(): void
    {
        config([
            'filesystems.default' => 'missing',
            'filesystems.disks' => [
                'doctor' => [
                    'driver' => 'local',
                    'root' => $this->root,
                ],
            ],
        ]);

        $result = (new FilesystemDisksAreReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Disk [missing] does not have a configured driver.', $result->details);
    }

    public function testProbesADiskRegisteredWithoutAConfigurationRecord(): void
    {
        config(['filesystems.default' => 'registered']);
        Storage::set('registered', Storage::build(['driver' => 'local', 'root' => $this->root]));

        $result = (new FilesystemDisksAreReachable)->check();
        $this->assertSame('pass', $result->status->value);
    }

    public function testLinksToFilesystemConfigurationWhenTheDefaultDiskCannotBeReached(): void
    {
        config([
            'filesystems.default' => 'doctor',
            'filesystems.disks.doctor' => [
                'driver' => 'local',
                'root' => $this->root . '/missing',
            ],
        ]);

        $result = (new FilesystemDisksAreReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertEquals([Link::docs('filesystem', 'configuration')], $result->links);
    }
}
