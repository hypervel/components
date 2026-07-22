<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class StorageCommandTest extends TestCase
{
    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;

        $this->files->ensureDirectoryExists($this->app->publicPath());
        $this->files->ensureDirectoryExists($this->app->storagePath('app/public'));
    }

    protected function tearDown(): void
    {
        $linkPath = $this->app->publicPath('storage');

        if (is_link($linkPath)) {
            $this->files->delete($linkPath);
        }

        parent::tearDown();
    }

    public function testStorageLinkCreatesSymlink(): void
    {
        $this->artisan('storage:link')
            ->assertSuccessful()
            ->expectsOutputToContain('connected');

        $this->assertTrue(is_link($this->app->publicPath('storage')));
    }

    public function testStorageLinkFailsWhenLinkAlreadyExists(): void
    {
        $this->artisan('storage:link')->assertSuccessful();

        $this->artisan('storage:link')
            ->expectsOutputToContain('already exists');
    }

    public function testStorageLinkRecreatesWithForce(): void
    {
        $this->artisan('storage:link')->assertSuccessful();

        $this->artisan('storage:link', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('connected');

        $this->assertTrue(is_link($this->app->publicPath('storage')));
    }

    public function testStorageLinkCreatesRelativeSymlink(): void
    {
        $this->artisan('storage:link', ['--relative' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('connected');

        $linkPath = $this->app->publicPath('storage');

        $this->assertTrue(is_link($linkPath));

        $target = readlink($linkPath);
        $this->assertFalse(str_starts_with($target, '/'), 'Expected a relative symlink target, got absolute: ' . $target);
    }

    public function testStorageLinkRecognizesBrokenSymlinkWithoutForce(): void
    {
        $link = $this->app->publicPath('storage');

        symlink($this->app->storagePath('missing'), $link);

        $this->artisan('storage:link')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertTrue(is_link($link));
    }

    public function testStorageLinkReplacesBrokenSymlinkWithForce(): void
    {
        $link = $this->app->publicPath('storage');
        $target = $this->app->storagePath('app/public');

        symlink($this->app->storagePath('missing'), $link);

        $this->artisan('storage:link', ['--force' => true])
            ->expectsOutputToContain('connected')
            ->assertSuccessful();

        $this->assertTrue(is_link($link));
        $this->assertSame($target, readlink($link));
    }

    public function testStorageLinkFailsWhenForcedLinkRemovalLeavesTheLink(): void
    {
        $link = $this->app->publicPath('storage');
        $target = $this->app->storagePath('app/public');

        symlink($target, $link);

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($link)->andReturnFalse();
        $this->app->instance('files', $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the existing link [{$link}].");

        $this->artisan('storage:link', ['--force' => true]);
    }

    public function testStorageLinkAcceptsConcurrentDisappearanceDuringForcedRemoval(): void
    {
        $link = $this->app->publicPath('storage');
        $target = $this->app->storagePath('app/public');

        symlink($target, $link);

        $files = m::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('delete')->once()->with($link)->andReturnUsing(function () use ($link): bool {
            unlink($link);

            return false;
        });
        $this->app->instance('files', $files);

        $this->artisan('storage:link', ['--force' => true])
            ->expectsOutputToContain('connected')
            ->assertSuccessful();

        $this->assertTrue(is_link($link));
    }

    public function testStorageLinkFailsWhenNativeLinkCreationReturnsFalse(): void
    {
        $link = $this->app->publicPath('storage');
        $target = $this->app->storagePath('app/public');
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('link')->once()->with($target, $link)->andReturnFalse();
        $this->app->instance('files', $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create a link from [{$link}] to [{$target}].");

        $this->artisan('storage:link');
    }

    public function testStorageLinkAcceptsWindowsCompatibleNullAfterCreation(): void
    {
        $link = $this->app->publicPath('storage');
        $target = $this->app->storagePath('app/public');
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('link')
            ->once()
            ->with($target, $link)
            ->andReturnUsing(function () use ($target, $link): ?bool {
                symlink($target, $link);

                return null;
            });
        $this->app->instance('files', $files);

        $this->artisan('storage:link')
            ->expectsOutputToContain('connected')
            ->assertSuccessful();

        $this->assertTrue(is_link($link));
    }

    public function testStorageLinkFailsWhenCreationDoesNotEstablishTheLink(): void
    {
        $link = $this->app->publicPath('storage');
        $target = $this->app->storagePath('app/public');
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('link')->once()->with($target, $link)->andReturnTrue();
        $this->app->instance('files', $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create a link from [{$link}] to [{$target}].");

        $this->artisan('storage:link');
    }

    public function testStorageUnlinkRemovesSymlink(): void
    {
        $this->artisan('storage:link')->assertSuccessful();
        $this->assertTrue(is_link($this->app->publicPath('storage')));

        $this->artisan('storage:unlink')
            ->assertSuccessful()
            ->expectsOutputToContain('deleted');

        $this->assertFalse(is_link($this->app->publicPath('storage')));
    }

    public function testStorageUnlinkRemovesBrokenSymlink(): void
    {
        $link = $this->app->publicPath('storage');

        symlink($this->app->storagePath('missing'), $link);

        $this->artisan('storage:unlink')
            ->expectsOutputToContain('deleted')
            ->assertSuccessful();

        $this->assertFalse(is_link($link));
    }

    public function testStorageUnlinkFailsWhenLinkRemains(): void
    {
        $link = $this->app->publicPath('storage');

        symlink($this->app->storagePath('missing'), $link);

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($link)->andReturnFalse();
        $this->app->instance('files', $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the link [{$link}].");

        $this->artisan('storage:unlink');
    }

    public function testStorageUnlinkAcceptsConcurrentDisappearance(): void
    {
        $link = $this->app->publicPath('storage');

        symlink($this->app->storagePath('missing'), $link);

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($link)->andReturnUsing(function () use ($link): bool {
            unlink($link);

            return false;
        });
        $this->app->instance('files', $files);

        $this->artisan('storage:unlink')
            ->expectsOutputToContain('deleted')
            ->assertSuccessful();

        $this->assertFalse(is_link($link));
    }

    public function testStorageUnlinkDoesNothingWhenNoLink(): void
    {
        $this->artisan('storage:unlink')
            ->assertSuccessful();
    }
}
