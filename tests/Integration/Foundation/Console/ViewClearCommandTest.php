<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class ViewClearCommandTest extends TestCase
{
    protected string $compiledPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiledPath = storage_path('framework/testing/view-clear');

        (new Filesystem)->ensureDirectoryExists($this->compiledPath);
        $this->app->make('config')->set('view.compiled', $this->compiledPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->compiledPath);

        parent::tearDown();
    }

    public function testViewClearCommandDeletesEveryCompiledView(): void
    {
        file_put_contents($this->compiledPath . '/first.php', 'first');
        mkdir($this->compiledPath . '/nested');
        file_put_contents($this->compiledPath . '/nested/second.php', 'second');

        $this->artisan('view:clear')
            ->expectsOutputToContain('Compiled views cleared successfully.')
            ->assertSuccessful();

        $this->assertSame(['.', '..'], scandir($this->compiledPath));
    }

    public function testViewClearCommandFailsWhenCompiledViewsCannotBeEnumerated(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('glob')
            ->once()
            ->with($this->compiledPath . '/*')
            ->andReturnFalse();
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to enumerate compiled views in [{$this->compiledPath}].");

        $this->artisan('view:clear');
    }

    public function testViewClearCommandAttemptsEveryEntryAndPreservesTheEarliestFailure(): void
    {
        $first = $this->compiledPath . '/first.php';
        $second = $this->compiledPath . '/second.php';
        $exception = new RuntimeException('first deletion failed');
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('glob')
            ->once()
            ->with($this->compiledPath . '/*')
            ->andReturn([$first, $second]);
        $files->shouldReceive('isDirectory')->once()->with($first)->andReturnFalse();
        $files->shouldReceive('delete')->once()->with($first)->andThrow($exception);
        $files->shouldReceive('isDirectory')->once()->with($second)->andReturnFalse();
        $files->shouldReceive('delete')->once()->with($second)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($second)->andReturnTrue();
        $this->app->instance(Filesystem::class, $files);

        $this->expectExceptionObject($exception);

        $this->artisan('view:clear');
    }

    public function testViewClearCommandAcceptsConcurrentDisappearance(): void
    {
        $view = $this->compiledPath . '/view.php';
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('glob')
            ->once()
            ->with($this->compiledPath . '/*')
            ->andReturn([$view]);
        $files->shouldReceive('isDirectory')->once()->with($view)->andReturnFalse();
        $files->shouldReceive('delete')->once()->with($view)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($view)->andReturnFalse();
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('view:clear')
            ->expectsOutputToContain('Compiled views cleared successfully.')
            ->assertSuccessful();
    }
}
