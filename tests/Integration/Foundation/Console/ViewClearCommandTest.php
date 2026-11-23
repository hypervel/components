<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Blade;
use Hypervel\Testbench\TestCase;
use Hypervel\View\Component;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class ViewClearCommandTest extends TestCase
{
    use VerifiesDoubles;

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

    public function testViewClearCommandAllowsInlineViewsToBeRenderedAgainInTheSameProcess(): void
    {
        $contents = 'Hello {{ $name }}';
        $source = $this->compiledPath . '/' . hash('xxh128', $contents) . '.blade.php';
        $compiled = $this->app->make('blade.compiler')->getCompiledPath($source);

        $this->assertSame('Hello Taylor', Blade::render($contents, ['name' => 'Taylor']));
        $this->assertFileExists($source);
        $this->assertFileExists($compiled);

        $this->artisan('view:clear')->assertSuccessful();

        $this->assertFileDoesNotExist($source);
        $this->assertFileDoesNotExist($compiled);
        $this->assertSame('Hello Taylor', Blade::render($contents, ['name' => 'Taylor']));
        $this->assertFileExists($source);
        $this->assertFileExists($compiled);
    }

    public function testViewClearCommandFailsWhenCompiledViewsCannotBeEnumerated(): void
    {
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('glob')
            ->with($this->compiledPath . '/*')
            ->returns(false);
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Unable to enumerate compiled views in [{$this->compiledPath}].");

        $this->artisan('view:clear');
    }

    public function testViewClearCommandAttemptsEveryEntryAndPreservesTheEarliestFailure(): void
    {
        $first = $this->compiledPath . '/first.php';
        $second = $this->compiledPath . '/second.php';
        $exception = new RuntimeException('first deletion failed');
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('glob')
            ->with($this->compiledPath . '/*')
            ->returns([$first, $second]);
        $files->expects('isDirectory')->with($first)->returns(false);
        $files->expects('delete')->with($first)->throws($exception);
        $files->expects('isDirectory')->with($second)->returns(false);
        $files->expects('delete')->with($second)->returns(false);
        $files->expects('exists')->with($second)->returns(true);
        $this->app->instance(Filesystem::class, $files);

        $this->expectExceptionObject($exception);

        $this->artisan('view:clear');
    }

    public function testViewClearCommandFlushesInProcessCachesBeforeRethrowingDeletionFailure(): void
    {
        $contents = 'Hello {{ $name }}';
        $this->assertSame('Hello Taylor', Blade::render($contents, ['name' => 'Taylor']));
        $this->assertSame('Hello Taylor', Blade::render($contents, ['name' => 'Taylor']));

        $component = new ViewClearInlineComponent;
        $engine = $this->app->make('view.engine.resolver')->resolve('blade');
        (fn () => static::$compiledOrNotExpired = ['view' => true])->call($engine);

        $this->assertNotSame([], (fn () => static::$bladeViewCache)->call($component));
        $this->assertNotSame([], (fn () => static::$compiledOrNotExpired)->call($engine));

        $view = $this->compiledPath . '/view.php';
        $exception = new RuntimeException('deletion failed');
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('glob')->with($this->compiledPath . '/*')->returns([$view]);
        $files->expects('isDirectory')->with($view)->returns(false);
        $files->expects('delete')->with($view)->throws($exception);
        $this->app->instance(Filesystem::class, $files);

        $caught = null;

        try {
            $this->artisan('view:clear');
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($exception, $caught);
        $this->assertSame([], (fn () => static::$bladeViewCache)->call($component));
        $this->assertSame([], (fn () => static::$compiledOrNotExpired)->call($engine));
    }

    public function testViewClearCommandAcceptsConcurrentDisappearance(): void
    {
        $view = $this->compiledPath . '/view.php';
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('glob')
            ->with($this->compiledPath . '/*')
            ->returns([$view]);
        $files->expects('isDirectory')->with($view)->returns(false);
        $files->expects('delete')->with($view)->returns(false);
        $files->expects('exists')->with($view)->returns(false);
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('view:clear')
            ->expectsOutputToContain('Compiled views cleared successfully.')
            ->assertSuccessful();
    }
}

class ViewClearInlineComponent extends Component
{
    public function render(): string
    {
        return 'View clear';
    }
}
