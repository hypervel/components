<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\FilesystemDisksAreReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Filesystem\LocalFilesystemAdapter;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use PHPUnit\Framework\Attributes\TestWith;

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

    #[TestWith([null])]
    #[TestWith([[]])]
    public function testReportsADefaultDiskWithoutADriver(?array $record): void
    {
        config(['filesystems.default' => 'missing', 'filesystems.disks' => $record === null ? [] : ['missing' => $record]]);

        $result = (new FilesystemDisksAreReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('The default filesystem disk [missing] does not have a configured driver.', $result->summary);
        $this->assertStringContainsString('FILESYSTEM_DISK', $result->remediation);
    }

    public function testProbesADiskRegisteredWithoutAConfigurationRecord(): void
    {
        config(['filesystems.default' => 'registered']);
        $adapter = new LocalAdapter($this->root);
        Storage::set('registered', new LocalFilesystemAdapter(new Filesystem($adapter), $adapter));

        $result = (new FilesystemDisksAreReachable)->check();
        $this->assertSame('pass', $result->status->value);

        File::deleteDirectory($this->root);
        $result = (new FilesystemDisksAreReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('The registered local disk root does not exist.', $result->details);
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function testChecksTheRootOfTheRegisteredDiskWhenItReplacesAConfiguredDisk(): void
    {
        config([
            'filesystems.default' => 'registered',
            'filesystems.disks.registered' => ['driver' => 'local', 'root' => $this->root],
        ]);
        $missing = $this->root . '/missing';
        Storage::set('registered', Storage::build(['driver' => 'local', 'root' => $missing, 'lazy_root_creation' => true]));

        $result = (new FilesystemDisksAreReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString($missing, $result->details);
        $this->assertDirectoryDoesNotExist($missing);
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
