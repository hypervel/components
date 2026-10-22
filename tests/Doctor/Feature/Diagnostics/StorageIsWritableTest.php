<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\StorageIsWritable;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class StorageIsWritableTest extends TestCase
{
    private string $basePath;

    /**
     * Isolate paths used by the storage repair.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorWritableStorage');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath, 0775, true);
        $this->app->setBasePath($this->basePath);
        $this->app->useStoragePath($this->basePath . '/storage');
    }

    /**
     * Remove the temporary storage tree.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenHypervelCanWriteToTheRequiredStorageDirectories(): void
    {
        foreach ($this->storagePaths() as $path) {
            File::ensureDirectoryExists($path);
        }

        $report = $this->app->make(Doctor::class)
            ->only('StorageIsWritable')->run();

        $outcome = $report->diagnostics()[0];

        $this->assertSame(StorageIsWritable::class, $outcome->diagnostic::class);
        $this->assertSame('pass', $outcome->result->status->value);
        $this->assertSame('The application storage directories are writable.', $outcome->result->summary);
    }

    public function testReportsMissingWritableStorageDirectories(): void
    {
        $report = $this->app->make(Doctor::class)
            ->only('StorageIsWritable')->run();

        $outcome = $report->diagnostics()[0];

        $this->assertSame('fail', $outcome->result->status->value);
        $this->assertStringContainsString($this->basePath . '/storage: directory does not exist', $outcome->result->details);
        $this->assertSame('Make the storage directories writable?', $outcome->result->confirmation);
        $this->assertSame('Ensure the listed storage and bootstrap cache directories exist and are writable by the PHP process.', $outcome->result->remediation);
    }

    public function testCycleBesideAHealthyChildCannotPassDiscovery(): void
    {
        config([
            'cache.default' => 'main',
            'cache.stores.main' => ['driver' => 'failover', 'stores' => ['main', 'array']],
        ]);

        $result = $this->app->make(Doctor::class)->check(new StorageIsWritable)->result;

        $this->assertSame('error', $result->status->value);
        $this->assertSame('Circular cache store definition detected: main -> main.', $result->summary);
    }

    public function testCreatesMissingWritableStorageDirectoriesWhenFixed(): void
    {
        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            new StorageIsWritable,
            DiagnosticResult::fail('The application cannot write to every required storage directory.')->fixable(),
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('The application storage directories are writable.', $fix->result->summary);

        foreach ($this->storagePaths() as $path) {
            $this->assertDirectoryExists($path);
            $this->assertTrue(is_writable($path));
        }
    }

    public function testRepairsRelocatedPathsWithoutChangingHealthyDirectoryPermissions(): void
    {
        $shared = $this->basePath . '/shared';
        mkdir($shared);
        chmod($shared, 01777);
        $this->app->useStoragePath($this->basePath . '/application-storage');
        config([
            'view.compiled' => $shared,
            'session.driver' => 'file',
            'session.files' => $this->basePath . '/sessions',
            'cache.default' => 'stack',
            'cache.stores.stack' => ['driver' => 'stack', 'stores' => ['files']],
            'cache.stores.files' => [
                'driver' => 'file',
                'path' => $this->basePath . '/cache-data',
                'lock_path' => $this->basePath . '/cache-locks',
            ],
        ]);
        $environment = Env::getRepository();
        $previous = $environment->get('APP_CONFIG_CACHE');
        $environment->set('APP_CONFIG_CACHE', $this->basePath . '/artifacts/config.php');

        try {
            $diagnostic = new StorageIsWritable;
            $result = $diagnostic->check();
            $this->assertSame('fail', $result->status->value);
            $this->assertSame('pass', $diagnostic->fix($result)->status->value);

            foreach (['application-storage/framework', 'sessions', 'cache-data', 'cache-locks', 'artifacts'] as $relative) {
                $this->assertDirectoryExists($this->basePath . '/' . $relative);
            }

            clearstatcache(true, $shared);
            $this->assertSame(01777, fileperms($shared) & 07777);
            $this->assertSame('pass', $diagnostic->check()->status->value);
            $this->assertSame([], File::files($shared, true));
        } finally {
            $previous === null ? $environment->clear('APP_CONFIG_CACHE') : $environment->set('APP_CONFIG_CACHE', $previous);
        }
    }

    public function testRepairAddsOwnerAccessWithoutWideningOtherPermissions(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('Requires Unix file permissions for an unprivileged user.');
        }

        foreach ($this->storagePaths() as $path) {
            File::ensureDirectoryExists($path);
        }

        $private = $this->basePath . '/private-views';
        mkdir($private);
        chmod($private, 01550);
        config(['view.compiled' => $private]);

        try {
            $diagnostic = new StorageIsWritable;
            $result = $diagnostic->check();
            $this->assertSame('fail', $result->status->value);
            $this->assertSame('pass', $diagnostic->fix($result)->status->value);

            clearstatcache(true, $private);
            $this->assertSame(01750, fileperms($private) & 07777);
        } finally {
            chmod($private, 0700);
        }
    }

    /**
     * Get the required storage directories and resolved bootstrap cache parents.
     *
     * @return list<string>
     */
    private function storagePaths(): array
    {
        return [
            $this->basePath . '/storage',
            $this->basePath . '/storage/app',
            $this->basePath . '/storage/framework',
            $this->basePath . '/storage/framework/cache',
            $this->basePath . '/storage/framework/sessions',
            $this->basePath . '/storage/framework/views',
            $this->basePath . '/storage/logs',
            dirname($this->app->getCachedConfigPath()),
            dirname($this->app->getCachedRoutesPath()),
            dirname($this->app->getCachedEventsPath()),
            dirname($this->app->getCachedPackagesPath()),
        ];
    }
}
