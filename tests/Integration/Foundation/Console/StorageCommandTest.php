<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;

/**
 * @internal
 * @coversNothing
 */
class StorageCommandTest extends \Hypervel\Testbench\TestCase
{
    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;

        // Ensure the public directory exists in the runtime copy
        $this->files->ensureDirectoryExists($this->app->publicPath());

        // Ensure the storage/app/public directory exists
        $this->files->ensureDirectoryExists($this->app->storagePath('app/public'));
    }

    protected function tearDown(): void
    {
        // Clean up any symlinks created during tests
        $linkPath = $this->app->publicPath('storage');

        if (is_link($linkPath)) {
            $this->files->delete($linkPath);
        }

        parent::tearDown();
    }

    public function testStorageLinkCreatesSymlink()
    {
        $this->artisan('storage:link')
            ->assertSuccessful()
            ->expectsOutputToContain('connected');

        $this->assertTrue(is_link($this->app->publicPath('storage')));
    }

    public function testStorageLinkFailsWhenLinkAlreadyExists()
    {
        // Create the link first
        $this->artisan('storage:link')->assertSuccessful();

        // Try again without --force
        $this->artisan('storage:link')
            ->expectsOutputToContain('already exists');
    }

    public function testStorageLinkRecreatesWithForce()
    {
        // Create the link first
        $this->artisan('storage:link')->assertSuccessful();

        // Recreate with --force
        $this->artisan('storage:link', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('connected');

        $this->assertTrue(is_link($this->app->publicPath('storage')));
    }

    public function testStorageLinkCreatesRelativeSymlink()
    {
        $this->artisan('storage:link', ['--relative' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('connected');

        $linkPath = $this->app->publicPath('storage');

        $this->assertTrue(is_link($linkPath));

        // A relative symlink's target should be a relative path, not absolute
        $target = readlink($linkPath);
        $this->assertFalse(str_starts_with($target, '/'), 'Expected a relative symlink target, got absolute: ' . $target);
    }

    public function testStorageUnlinkRemovesSymlink()
    {
        // Create the link first
        $this->artisan('storage:link')->assertSuccessful();
        $this->assertTrue(is_link($this->app->publicPath('storage')));

        // Remove it
        $this->artisan('storage:unlink')
            ->assertSuccessful()
            ->expectsOutputToContain('deleted');

        $this->assertFalse(is_link($this->app->publicPath('storage')));
    }

    public function testStorageUnlinkDoesNothingWhenNoLink()
    {
        // No link exists — command should succeed silently
        $this->artisan('storage:unlink')
            ->assertSuccessful();
    }
}
