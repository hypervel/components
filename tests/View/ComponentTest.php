<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Closure;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Contracts\View\Factory as FactoryContract;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\HtmlString;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\View\Component;
use Hypervel\View\ComponentSlot;
use Hypervel\View\Factory;
use Hypervel\View\View;
use Mockery as m;

class ComponentTest extends TestCase
{
    protected Factory $viewFactory;

    protected Config $config;

    protected Filesystem $filesystem;

    protected string $compiledPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewFactory = m::mock(Factory::class);
        $this->config = m::mock(Config::class);
        $this->filesystem = new Filesystem;
        $this->compiledPath = ParallelTesting::tempDir('ViewComponentTest');
        $this->filesystem->deleteDirectory($this->compiledPath);
        $this->filesystem->makeDirectory($this->compiledPath);

        $container = new Container;
        $container->instance('config', $this->config);
        $container->instance('view', $this->viewFactory);
        $container->instance(FactoryContract::class, $this->viewFactory);
        $container->instance(Filesystem::class, $this->filesystem);

        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->compiledPath);

        parent::tearDown();
    }

    public function testInlineViewsGetCreated(): void
    {
        $this->config->shouldReceive('string')->once()->with('view.compiled')->andReturn($this->compiledPath);
        $this->viewFactory->shouldReceive('exists')->once()->andReturn(false);
        $this->viewFactory->shouldReceive('replaceNamespace')->once()->with('__components', $this->compiledPath);

        $component = new TestInlineViewComponent;
        $this->assertSame('__components::57b7a54afa0eb51fd9b88eec031c9e9e', $component->resolveView());
    }

    public function testInlineViewsUseAtomicFilesystemPublication(): void
    {
        $contents = 'Atomically published';
        $viewFile = $this->compiledPath . '/' . hash('xxh128', $contents) . '.blade.php';
        $filesystem = m::mock(Filesystem::class);

        $filesystem->shouldReceive('exists')->once()->with($viewFile)->andReturn(false);
        $filesystem->shouldReceive('ensureDirectoryExists')->once()->with($this->compiledPath);
        $filesystem->shouldReceive('replace')->once()->with($viewFile, $contents);

        Container::getInstance()->instance(Filesystem::class, $filesystem);

        $this->config->shouldReceive('string')->once()->with('view.compiled')->andReturn($this->compiledPath);
        $this->viewFactory->shouldReceive('exists')->once()->with($contents)->andReturn(false);
        $this->viewFactory->shouldReceive('replaceNamespace')->once()->with('__components', $this->compiledPath);

        $component = new TestAtomicallyPublishedInlineViewComponent;

        $this->assertSame('__components::' . hash('xxh128', $contents), $component->resolveView());
    }

    public function testCompleteInlineViewsAreNotRepublished(): void
    {
        $contents = 'Already published';
        $viewFile = $this->compiledPath . '/' . hash('xxh128', $contents) . '.blade.php';
        $filesystem = m::mock(Filesystem::class);

        $filesystem->shouldReceive('exists')->once()->with($viewFile)->andReturn(true);
        $filesystem->shouldReceive('size')->once()->with($viewFile)->andReturn(strlen($contents));
        $filesystem->shouldReceive('ensureDirectoryExists')->never();
        $filesystem->shouldReceive('replace')->never();

        Container::getInstance()->instance(Filesystem::class, $filesystem);

        $this->config->shouldReceive('string')->once()->with('view.compiled')->andReturn($this->compiledPath);
        $this->viewFactory->shouldReceive('exists')->once()->with($contents)->andReturn(false);
        $this->viewFactory->shouldReceive('replaceNamespace')->once()->with('__components', $this->compiledPath);

        $component = new TestCompleteInlineViewComponent;

        $this->assertSame('__components::' . hash('xxh128', $contents), $component->resolveView());
    }

    public function testEmptyInlineViewsArePublished(): void
    {
        $this->config->shouldReceive('string')->once()->with('view.compiled')->andReturn($this->compiledPath);
        $this->viewFactory->shouldReceive('exists')->once()->with('')->andReturn(false);
        $this->viewFactory->shouldReceive('replaceNamespace')->once()->with('__components', $this->compiledPath);

        $component = new TestEmptyInlineViewComponent;
        $viewName = $component->resolveView();
        $viewFile = $this->compiledPath . '/' . str_replace('__components::', '', $viewName) . '.blade.php';

        $this->assertFileExists($viewFile);
        $this->assertSame(0, filesize($viewFile));
    }

    public function testTruncatedInlineViewsAreReplaced(): void
    {
        $contents = 'Hello {{ $title }}';
        $viewFile = $this->compiledPath . '/' . hash('xxh128', $contents) . '.blade.php';
        file_put_contents($viewFile, 'truncated');

        $this->config->shouldReceive('string')->once()->with('view.compiled')->andReturn($this->compiledPath);
        $this->viewFactory->shouldReceive('exists')->once()->with($contents)->andReturn(false);
        $this->viewFactory->shouldReceive('replaceNamespace')->once()->with('__components', $this->compiledPath);

        $component = new TestInlineViewComponent;

        $this->assertSame('__components::' . hash('xxh128', $contents), $component->resolveView());
        $this->assertSame($contents, file_get_contents($viewFile));
    }

    public function testRegularViewsGetReturnedUsingViewHelper()
    {
        $view = m::mock(View::class);
        $this->viewFactory->shouldReceive('make')->once()->with('alert', [], [])->andReturn($view);

        $component = new TestRegularViewComponentUsingViewHelper;

        $this->assertSame($view, $component->resolveView());
    }

    public function testRenderingStringClosureFromComponent(): void
    {
        $this->config->shouldReceive('string')->once()->with('view.compiled')->andReturn($this->compiledPath);
        $this->viewFactory->shouldReceive('exists')->once()->andReturn(false);
        $this->viewFactory->shouldReceive('replaceNamespace')->once()->with('__components', $this->compiledPath);

        $component = new class extends Component {
            protected $title;

            public function __construct($title = 'World')
            {
                $this->title = $title;
            }

            public function render(): ViewContract|Htmlable|Closure|string
            {
                return function (array $data) {
                    return "<p>Hello {$this->title}</p>";
                };
            }
        };

        $closure = $component->resolveView();

        $viewPath = $closure([]);

        $this->viewFactory->shouldReceive('make')->with($viewPath, [], [])->andReturn('<p>Hello World</p>');

        $this->assertInstanceOf(Closure::class, $closure);
        $this->assertSame('__components::9cc08f5001b343c093ee1a396da820dc', $viewPath);

        $hash = str_replace('__components::', '', $viewPath);
        $this->assertSame('<p>Hello World</p>', file_get_contents("{$this->compiledPath}/{$hash}.blade.php"));
    }

    public function testRegularViewsGetReturnedUsingViewMethod()
    {
        $view = m::mock(View::class);
        $this->viewFactory->shouldReceive('make')->once()->with('alert', [], [])->andReturn($view);

        $component = new TestRegularViewComponentUsingViewMethod;

        $this->assertSame($view, $component->resolveView());
    }

    public function testRegularViewNamesGetReturned(): void
    {
        $this->viewFactory->shouldReceive('exists')->once()->andReturn(true);
        $this->viewFactory->shouldReceive('replaceNamespace')->never();

        $component = new TestRegularViewNameViewComponent;

        $this->assertSame('alert', $component->resolveView());
    }

    public function testHtmlableGetReturned()
    {
        $component = new TestHtmlableReturningViewComponent;

        $view = $component->resolveView();

        $this->assertInstanceOf(Htmlable::class, $view);
        $this->assertSame('<p>Hello foo</p>', $view->toHtml());
    }

    public function testResolveWithUnresolvableDependency()
    {
        $this->expectException(BindingResolutionException::class);

        TestInlineViewComponentWhereRenderDependsOnProps::resolve([]);
    }

    public function testResolveDependenciesWithoutContainer()
    {
        $component = TestInlineViewComponentWhereRenderDependsOnProps::resolve(['content' => 'foo']);
        $this->assertSame('foo', $component->render());

        $component = new class extends Component {
            public $content;

            public function __construct($a = null, $b = null)
            {
                $this->content = $a . $b;
            }

            public function render(): ViewContract|Htmlable|Closure|string
            {
                return $this->content;
            }
        };

        $component = $component::resolve(['a' => 'a', 'b' => 'b']);
        $this->assertSame('ab', $component->render());
    }

    public function testResolveDependenciesWithContainerIfNecessary()
    {
        $component = TestInlineViewComponentWithContainerDependencies::resolve([]);
        $this->assertSame($this->viewFactory, $component->dependency);

        $component = TestInlineViewComponentWithContainerDependenciesAndProps::resolve(['content' => 'foo']);
        $this->assertSame($this->viewFactory, $component->dependency);
        $this->assertSame('foo', $component->render());
    }

    public function testResolveReturnsFreshInstancesAcrossCalls()
    {
        // Components capture per-render state in their constructors, so resolve()
        // must hand back a fresh instance every time — even when the constructor
        // has parameters with defaults and no data is supplied.
        TestStatefulInlineComponent::$constructed = 0;

        $first = TestStatefulInlineComponent::resolve([]);
        $second = TestStatefulInlineComponent::resolve([]);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, TestStatefulInlineComponent::$constructed);
    }

    public function testResolveComponentsUsing()
    {
        $component = new TestInlineViewComponent;

        Component::resolveComponentsUsing(function ($class, $data) use ($component) {
            $this->assertSame(Component::class, $class, 'It takes the component class name as the first parameter.');
            $this->assertSame(['foo' => 'bar'], $data, 'It takes the given data as the second parameter.');

            return $component;
        });

        $this->assertSame($component, Component::resolve(['foo' => 'bar']));
    }

    public function testBladeViewCacheWithRegularViewNameViewComponent(): void
    {
        $component = new TestRegularViewNameViewComponent;

        $this->viewFactory->shouldReceive('exists')->twice()->andReturn(true);

        $this->assertSame('alert', $component->resolveView());
        $this->assertSame('alert', $component->resolveView());
        $this->assertSame('alert', $component->resolveView());
        $this->assertSame('alert', $component->resolveView());

        $cache = (fn () => $component::$bladeViewCache)->call($component);
        $cacheKey = hash('xxh128', sprintf('%s::%s', $component::class, 'alert'));
        $this->assertSame([$cacheKey => 'alert'], $cache);

        $component::flushCache();

        $cache = (fn () => $component::$bladeViewCache)->call($component);
        $this->assertSame([], $cache);

        $this->assertSame('alert', $component->resolveView());
        $this->assertSame('alert', $component->resolveView());
        $this->assertSame('alert', $component->resolveView());
        $this->assertSame('alert', $component->resolveView());
    }

    public function testBladeViewCacheWithInlineViewComponent(): void
    {
        $component = new TestInlineViewComponent;

        $this->viewFactory->shouldReceive('exists')->twice()->andReturn(false);

        $this->config->shouldReceive('string')->twice()->with('view.compiled')->andReturn($this->compiledPath);

        $this->viewFactory->shouldReceive('replaceNamespace')
            ->with('__components', $this->compiledPath)
            ->twice();

        $compiledViewName = '__components::57b7a54afa0eb51fd9b88eec031c9e9e';
        $contents = 'Hello {{ $title }}';
        $cacheKey = hash('xxh128', sprintf('%s::%s', $component::class, $contents));

        $this->assertSame($compiledViewName, $component->resolveView());
        $this->assertSame($compiledViewName, $component->resolveView());
        $this->assertSame($compiledViewName, $component->resolveView());
        $this->assertSame($compiledViewName, $component->resolveView());

        $cache = (fn () => $component::$bladeViewCache)->call($component);
        $this->assertSame([$cacheKey => $compiledViewName], $cache);
        $this->assertSame(32, strlen($cacheKey));
        $this->assertStringNotContainsString($contents, $cacheKey);

        $component::flushCache();

        $cache = (fn () => $component::$bladeViewCache)->call($component);
        $this->assertSame([], $cache);

        $this->assertSame($compiledViewName, $component->resolveView());
        $this->assertSame($compiledViewName, $component->resolveView());
        $this->assertSame($compiledViewName, $component->resolveView());
        $this->assertSame($compiledViewName, $component->resolveView());
    }

    public function testBladeViewCacheWithInlineViewComponentWhereRenderDependsOnProps(): void
    {
        $componentA = new TestInlineViewComponentWhereRenderDependsOnProps('A');
        $componentB = new TestInlineViewComponentWhereRenderDependsOnProps('B');

        $this->viewFactory->shouldReceive('exists')->twice()->andReturn(false);

        $this->config->shouldReceive('string')->twice()->with('view.compiled')->andReturn($this->compiledPath);

        $this->viewFactory->shouldReceive('replaceNamespace')
            ->with('__components', $this->compiledPath)
            ->twice();

        $compiledViewNameA = '__components::9b0498cbe3839becd0d496e05c553485';
        $compiledViewNameB = '__components::9d1b9bc4078a3e7274d3766ca02423f3';
        $cacheAKey = hash('xxh128', sprintf('%s::%s', $componentA::class, 'A'));
        $cacheBKey = hash('xxh128', sprintf('%s::%s', $componentB::class, 'B'));

        $this->assertSame($compiledViewNameA, $componentA->resolveView());
        $this->assertSame($compiledViewNameA, $componentA->resolveView());
        $this->assertSame($compiledViewNameB, $componentB->resolveView());
        $this->assertSame($compiledViewNameB, $componentB->resolveView());

        $cacheA = (fn () => $componentA::$bladeViewCache)->call($componentA);
        $cacheB = (fn () => $componentB::$bladeViewCache)->call($componentB);
        $this->assertSame($cacheA, $cacheB);
        $this->assertSame([
            $cacheAKey => $compiledViewNameA,
            $cacheBKey => $compiledViewNameB,
        ], $cacheA);

        $componentA::flushCache();

        $cacheA = (fn () => $componentA::$bladeViewCache)->call($componentA);
        $cacheB = (fn () => $componentB::$bladeViewCache)->call($componentB);
        $this->assertSame($cacheA, $cacheB);
        $this->assertSame([], $cacheA);
    }

    public function testFactoryGetsSharedBetweenComponents()
    {
        $regular = new TestRegularViewNameViewComponent;
        $inline = new TestInlineViewComponent;

        $getFactory = fn ($component) => (fn () => $component->factory())->call($component);

        $this->assertSame($this->viewFactory, $getFactory($regular));

        $this->assertSame($this->viewFactory, $getFactory($inline));
    }

    public function testComponentSlotIsEmpty()
    {
        $slot = new ComponentSlot;

        $this->assertTrue((bool) $slot->isEmpty());
    }

    public function testComponentSlotSanitizedEmpty()
    {
        // default sanitizer should remove all html tags
        $slot = new ComponentSlot('<!-- test -->');

        $linebreakingSlot = new ComponentSlot("\n  \t");

        $moreComplexSlot = new ComponentSlot('<!--
        <p>commented HTML</p>
        <img border="0" src="" alt="">
        -->');

        $this->assertFalse((bool) $slot->hasActualContent());
        $this->assertFalse((bool) $linebreakingSlot->hasActualContent('trim'));
        $this->assertFalse((bool) $moreComplexSlot->hasActualContent());
    }

    public function testComponentSlotSanitizedNotEmpty()
    {
        // default sanitizer should remove all html tags
        $slot = new ComponentSlot('<!-- test -->not empty');

        $linebreakingSlot = new ComponentSlot("\ntest  \t");

        $moreComplexSlot = new ComponentSlot('before<!--
        <p>commented HTML</p>
        <img border="0" src="" alt="">
        -->after');

        $this->assertTrue((bool) $slot->hasActualContent());
        $this->assertTrue((bool) $linebreakingSlot->hasActualContent('trim'));
        $this->assertTrue((bool) $moreComplexSlot->hasActualContent());
    }

    public function testComponentSlotIsNotEmpty()
    {
        $slot = new ComponentSlot('test');

        $anotherSlot = new ComponentSlot('test<!-- test -->');

        $moreComplexSlot = new ComponentSlot('t<!--
        <p>Look at this cool image:</p>
        <img border="0" src="pic_trulli.jpg" alt="Trulli">
        -->est');

        $this->assertTrue((bool) $slot->hasActualContent());
        $this->assertTrue((bool) $anotherSlot->hasActualContent());
        $this->assertTrue((bool) $moreComplexSlot->hasActualContent());
    }
}

class TestInlineViewComponent extends Component
{
    public $title;

    public function __construct($title = 'foo')
    {
        $this->title = $title;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return 'Hello {{ $title }}';
    }
}

class TestAtomicallyPublishedInlineViewComponent extends Component
{
    public function render(): ViewContract|Htmlable|Closure|string
    {
        return 'Atomically published';
    }
}

class TestCompleteInlineViewComponent extends Component
{
    public function render(): ViewContract|Htmlable|Closure|string
    {
        return 'Already published';
    }
}

class TestEmptyInlineViewComponent extends Component
{
    public function render(): ViewContract|Htmlable|Closure|string
    {
        return '';
    }
}

class TestInlineViewComponentWithContainerDependencies extends Component
{
    public $dependency;

    public function __construct(FactoryContract $dependency)
    {
        $this->dependency = $dependency;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return '';
    }
}

class TestInlineViewComponentWithContainerDependenciesAndProps extends Component
{
    public $content;

    public $dependency;

    public function __construct(FactoryContract $dependency, $content)
    {
        $this->content = $content;
        $this->dependency = $dependency;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return $this->content;
    }
}

class TestInlineViewComponentWithoutDependencies extends Component
{
    public function render(): ViewContract|Htmlable|Closure|string
    {
        return 'alert';
    }
}

class TestStatefulInlineComponent extends Component
{
    public static int $constructed = 0;

    public function __construct(public string $title = 'foo')
    {
        ++self::$constructed;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return '';
    }
}

class TestInlineViewComponentWhereRenderDependsOnProps extends Component
{
    public $content;

    public function __construct($content)
    {
        $this->content = $content;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return $this->content;
    }
}

class TestRegularViewComponentUsingViewHelper extends Component
{
    public $title;

    public function __construct($title = 'foo')
    {
        $this->title = $title;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return view('alert');
    }
}

class TestRegularViewComponentUsingViewMethod extends Component
{
    public $title;

    public function __construct($title = 'foo')
    {
        $this->title = $title;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return $this->view('alert');
    }
}

class TestRegularViewNameViewComponent extends Component
{
    public $title;

    public function __construct($title = 'foo')
    {
        $this->title = $title;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return 'alert';
    }
}

class TestHtmlableReturningViewComponent extends Component
{
    protected $title;

    public function __construct($title = 'foo')
    {
        $this->title = $title;
    }

    public function render(): ViewContract|Htmlable|Closure|string
    {
        return new HtmlString("<p>Hello {$this->title}</p>");
    }
}
