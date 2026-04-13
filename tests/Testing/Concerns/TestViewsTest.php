<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\ParallelTesting as ParallelTestingFacade;
use Hypervel\Testing\Concerns\TestViews;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class TestViewsTest extends TestCase
{
    private mixed $originalParallelTesting;

    protected function setUp(): void
    {
        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;

        parent::setUp();

        Container::setInstance($container = new Container);

        Facade::setFacadeApplication($container);

        $container->singleton('config', fn () => new Config([
            'view' => [
                'compiled' => '/path/to/compiled/views',
            ],
        ]));

        $container->singleton(ParallelTesting::class, fn ($app) => new ParallelTesting($app));

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        ParallelTestingFacade::clearResolvedInstance();
        Facade::setFacadeApplication(null);

        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        parent::tearDown();
    }

    public function testCompiledViewPathAppendsToken()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '5');

        $this->assertSame('/path/to/compiled/views/test_5', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathTrimsTrailingSlash()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '3');

        Container::getInstance()['config']->set('view.compiled', '/path/to/compiled/views/');

        $this->assertSame('/path/to/compiled/views/test_3', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathWithDifferentToken()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '42');

        Container::getInstance()['config']->set('view.compiled', '/var/www/storage/views');

        $this->assertSame('/var/www/storage/views/test_42', $this->getCompiledViewPath());
    }

    public function testCompiledViewPathReturnsNullWhenEmpty()
    {
        Container::getInstance()['config']->set('view.compiled', '');

        $this->assertNull($this->getCompiledViewPath());
    }

    public function testSwitchToCompiledViewPathUpdatesConfig()
    {
        $this->switchToCompiledViewPath('/new/compiled/path');

        $this->assertSame('/new/compiled/path', Container::getInstance()['config']->get('view.compiled'));
    }

    public function testSwitchToCompiledViewPathUpdatesCompilerCachePath()
    {
        $container = Container::getInstance();
        $compiler = new BladeCompiler(m::mock(Filesystem::class), '/original/path');

        $container->instance('blade.compiler', $compiler);

        $this->switchToCompiledViewPath('/new/compiled/path');

        $this->assertSame('/new/compiled/path', $container['config']->get('view.compiled'));
        $this->assertSame('/new/compiled/path', (new ReflectionProperty($compiler, 'cachePath'))->getValue($compiler));
    }

    public function testTearDownProcessDeletesCompiledViewDirectory()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '7');

        $instance = $this->makeTestViewsInstance();

        (new ReflectionProperty($instance::class, 'originalCompiledViewPath'))->setValue(null, null);

        $method = new ReflectionMethod($instance, 'bootTestViews');
        $method->invoke($instance);

        $parallelTesting = Container::getInstance()->make(ParallelTesting::class);
        $tearDownCallbacks = (new ReflectionProperty($parallelTesting, 'tearDownProcessCallbacks'))->getValue($parallelTesting);

        $this->assertCount(1, $tearDownCallbacks);
    }

    protected function getCompiledViewPath(): ?string
    {
        $instance = $this->makeTestViewsInstance();

        (new ReflectionProperty($instance::class, 'originalCompiledViewPath'))->setValue(null, null);

        $method = new ReflectionMethod($instance, 'parallelSafeCompiledViewPath');

        return $method->invoke($instance);
    }

    protected function switchToCompiledViewPath(string $path): void
    {
        $instance = $this->makeTestViewsInstance();

        $method = new ReflectionMethod($instance, 'switchToCompiledViewPath');
        $method->invoke($instance, $path);
    }

    protected function makeTestViewsInstance(): object
    {
        return new class {
            use TestViews;

            public $app;

            public function __construct()
            {
                $this->app = Container::getInstance();
            }
        };
    }
}
