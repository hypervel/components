<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Facade;
use Hypervel\Testing\Concerns\TestViews;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;

class TestViewsTest extends TestCase
{
    private mixed $originalParallelTesting;

    private string $tempDir;

    private Filesystem $filesystem;

    /**
     * Create the isolated compiled-view directory and container bindings.
     */
    protected function setUp(): void
    {
        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;

        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDir = ParallelTesting::tempDir('TestViewsTest');
        $this->filesystem->deleteDirectory($this->tempDir);
        $this->filesystem->ensureDirectoryExists($this->tempDir);

        Container::setInstance($container = new Container);

        Facade::setFacadeApplication($container);

        $container->singleton('config', fn () => new Config([
            'view' => [
                'compiled' => '/path/to/compiled/views',
            ],
        ]));

        $container->singleton(ParallelTesting::class, fn ($app) => new ParallelTesting($app));
        $container->instance('files', $this->filesystem);

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
    }

    /**
     * Remove the isolated compiled-view directory and restore the environment.
     */
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);

        Facade::setFacadeApplication(null);

        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        parent::tearDown();
    }

    public function testCompiledViewPathAppendsToken(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '5');

        $this->assertSame('/path/to/compiled/views/test_5', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathTrimsTrailingSlash(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '3');

        Container::getInstance()->make('config')->set('view.compiled', '/path/to/compiled/views/');

        $this->assertSame('/path/to/compiled/views/test_3', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathWithDifferentToken(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '42');

        Container::getInstance()->make('config')->set('view.compiled', '/var/www/storage/views');

        $this->assertSame('/var/www/storage/views/test_42', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathDoesNotReuseCustomPathFromPreviousCall(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '1');

        Container::getInstance()->make('config')->set('view.compiled', '/custom/views');

        $this->assertSame('/custom/views/test_1', $this->getCompiledViewPath());

        Container::getInstance()->make('config')->set('view.compiled', '/path/to/compiled/views');

        $this->assertSame('/path/to/compiled/views/test_1', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathDoesNotDoubleAppendToken(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '1');
        Container::getInstance()->make('config')->set('view.compiled', '/path/to/compiled/views/test_1');

        $this->assertSame('/path/to/compiled/views/test_1', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathReturnsNullWhenEmpty(): void
    {
        Container::getInstance()->make('config')->set('view.compiled', '');

        $this->assertNull($this->getCompiledViewPath());
    }

    public function testSwitchToCompiledViewPathUpdatesConfig(): void
    {
        $this->switchToCompiledViewPath('/new/compiled/path');

        $this->assertSame('/new/compiled/path', Container::getInstance()->make('config')->get('view.compiled'));
    }

    public function testSwitchToCompiledViewPathUpdatesCompilerCachePath(): void
    {
        $container = Container::getInstance();
        $compiler = new BladeCompiler(m::mock(Filesystem::class), '/original/path');

        $container->instance('blade.compiler', $compiler);

        $this->switchToCompiledViewPath('/new/compiled/path');

        $this->assertSame('/new/compiled/path', $container->make('config')->get('view.compiled'));
        $this->assertSame('/new/compiled/path', (new ReflectionProperty($compiler, 'cachePath'))->getValue($compiler));
    }

    public function testTearDownProcessDeletesCompiledViewDirectory(): void
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '7');
        Container::getInstance()->make('config')->set('view.compiled', $this->tempDir);

        $this->filesystem->put($this->tempDir . '/shared.php', 'shared view');

        $instance = $this->makeTestViewsInstance();

        $method = new ReflectionMethod($instance, 'bootTestViews');
        $method->invoke($instance);

        $parallelTesting = Container::getInstance()->make(ParallelTesting::class);
        $tearDownCallbacks = (new ReflectionProperty($parallelTesting, 'tearDownProcessCallbacks'))->getValue($parallelTesting);

        $this->assertCount(1, $tearDownCallbacks);

        $parallelTesting->callSetUpProcessCallbacks();

        $this->assertDirectoryExists($this->tempDir . '/test_7');

        $this->filesystem->put($this->tempDir . '/test_7/compiled.php', 'compiled view');

        $parallelTesting->callSetUpTestCaseCallbacks($this);
        $parallelTesting->callTearDownProcessCallbacks();

        $this->assertDirectoryDoesNotExist($this->tempDir . '/test_7');
        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileExists($this->tempDir . '/shared.php');
    }

    /**
     * Get the compiled view path for the current process.
     */
    protected function getCompiledViewPath(): ?string
    {
        $instance = $this->makeTestViewsInstance();

        $method = new ReflectionMethod($instance, 'parallelSafeCompiledViewPath');

        return $method->invoke($instance);
    }

    /**
     * Switch to the given compiled view path.
     */
    protected function switchToCompiledViewPath(string $path): void
    {
        $instance = $this->makeTestViewsInstance();

        $method = new ReflectionMethod($instance, 'switchToCompiledViewPath');
        $method->invoke($instance, $path);
    }

    /**
     * Create a test views instance using the current container.
     */
    protected function makeTestViewsInstance(): object
    {
        return new class {
            use TestViews;

            public Container $app;

            /**
             * Create a new test views instance.
             */
            public function __construct()
            {
                $this->app = Container::getInstance();
            }
        };
    }
}
