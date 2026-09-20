<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Closure;
use ErrorException;
use Generator;
use Hypervel\Container\Container as ContainerInstance;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Contracts\View\Engine;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Support\HtmlString;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\CompilerInterface;
use Hypervel\View\Engines\CompilerEngine;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Engines\PhpEngine;
use Hypervel\View\Factory;
use Hypervel\View\FileViewFinder;
use Hypervel\View\View;
use Hypervel\View\ViewFinderInterface;
use InvalidArgumentException;
use Mockery as m;
use ReflectionFunction;
use RuntimeException;
use stdClass;

class ViewFactoryTest extends TestCase
{
    public function testCloneIsolatesFinderAndSharedEnvironment(): void
    {
        $factory = new Factory(
            m::mock(EngineResolver::class),
            new FileViewFinder(new Filesystem, [__DIR__ . '/Fixtures']),
            m::mock(DispatcherContract::class)
        );

        $clone = clone $factory;
        $clone->replaceNamespace('foo', __DIR__ . '/Fixtures/namespaced');
        $clone->share('scoped', 'value');

        $this->assertSame(__DIR__ . '/Fixtures/namespaced/basic.php', $clone->getFinder()->find('foo::basic'));
        $this->assertSame($factory, $factory->shared('__env'));
        $this->assertSame($clone, $clone->shared('__env'));
        $this->assertNull($factory->shared('scoped'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No hint path defined for [foo].');

        $factory->getFinder()->find('foo::basic');
    }

    public function testMakeCreatesNewViewInstanceWithProperPathAndEngine(): void
    {
        unset($_SERVER['__test.view']);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('view')->andReturn('path.php');
        $engine = m::mock(Engine::class);
        $factory->getEngineResolver()->expects('resolve')->with('php')->andReturn($engine);
        $factory->getFinder()->expects('addExtension')->with('php');
        $factory->setDispatcher($this->createEventDispatcher());
        $factory->setContainer(m::mock(Container::class));
        $factory->creator('view', function ($view) {
            $_SERVER['__test.view'] = $view;
        });
        $factory->addExtension('php', 'php');
        $view = $factory->make('view', ['foo' => 'bar'], ['baz' => 'boom']);

        $this->assertSame($engine, $view->getEngine());
        $this->assertSame($_SERVER['__test.view'], $view);

        unset($_SERVER['__test.view']);
    }

    /**
     * Create an event dispatcher with an application container.
     */
    private function createEventDispatcher(): EventDispatcher
    {
        return new EventDispatcher(new Application);
    }

    public function testExistsPassesAndFailsViews(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('foo')->andThrow(InvalidArgumentException::class);
        $factory->getFinder()->expects('find')->with('bar')->andReturn('path.php');

        $this->assertFalse($factory->exists('foo'));
        $this->assertTrue($factory->exists('bar'));
    }

    public function testRenderingOnceChecks(): void
    {
        $factory = $this->getFactory();
        $this->assertFalse($factory->hasRenderedOnce('foo'));
        $factory->markAsRenderedOnce('foo');
        $this->assertTrue($factory->hasRenderedOnce('foo'));
        $factory->flushState();
        $this->assertFalse($factory->hasRenderedOnce('foo'));
    }

    public function testFirstCreatesNewViewInstanceWithProperPath(): void
    {
        unset($_SERVER['__test.view']);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->times(2)->with('view')->andReturn('path.php');
        $factory->getFinder()->expects('find')->with('bar')->andThrow(InvalidArgumentException::class);
        $engine = m::mock(Engine::class);
        $factory->getEngineResolver()->expects('resolve')->with('php')->andReturn($engine);
        $factory->getFinder()->expects('addExtension')->with('php');
        $factory->setDispatcher($this->createEventDispatcher());
        $factory->setContainer(m::mock(Container::class));
        $factory->creator('view', function ($view) {
            $_SERVER['__test.view'] = $view;
        });
        $factory->addExtension('php', 'php');
        $view = $factory->first(['bar', 'view'], ['foo' => 'bar'], ['baz' => 'boom']);

        $this->assertInstanceOf(ViewContract::class, $view);
        $this->assertSame($engine, $view->getEngine());
        $this->assertSame($_SERVER['__test.view'], $view);

        unset($_SERVER['__test.view']);
    }

    public function testFirstAcceptsZeroAsAViewName(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->times(2)->with('0')->andReturn('0.php');
        $factory->getEngineResolver()->expects('resolve')->with('php')->andReturn(m::mock(Engine::class));
        $factory->getDispatcher()->expects('hasListeners')->with('creating: 0')->andReturn(false);

        $this->assertSame('0', $factory->first(['0'])->name());
    }

    public function testFirstThrowsInvalidArgumentExceptionIfNoneFound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('view')->andThrow(InvalidArgumentException::class);
        $factory->getFinder()->expects('find')->with('bar')->andThrow(InvalidArgumentException::class);
        $factory->getFinder()->expects('addExtension')->with('php');
        $factory->addExtension('php', 'php');
        $factory->first(['bar', 'view'], ['foo' => 'bar'], ['baz' => 'boom']);
    }

    public function testRenderEachCreatesViewForEachItemInArray(): void
    {
        $factory = m::mock(Factory::class . '[make]', $this->getFactoryArgs());
        $mockView1 = m::mock(ViewContract::class);
        $factory->expects('make')->with('foo', ['key' => 'bar', 'value' => 'baz'])->andReturn($mockView1);
        $mockView2 = m::mock(ViewContract::class);
        $factory->expects('make')->with('foo', ['key' => 'breeze', 'value' => 'boom'])->andReturn($mockView2);
        $mockView1->expects('render')->andReturn('dayle');
        $mockView2->expects('render')->andReturn('rees');

        $result = $factory->renderEach('foo', ['bar' => 'baz', 'breeze' => 'boom'], 'value');

        $this->assertSame('daylerees', $result);
    }

    public function testEmptyViewsCanBeReturnedFromRenderEach(): void
    {
        $factory = m::mock(Factory::class . '[make]', $this->getFactoryArgs());
        $mockView = m::mock(ViewContract::class);
        $factory->expects('make')->with('foo')->andReturn($mockView);
        $mockView->expects('render')->andReturn('empty');

        $this->assertSame('empty', $factory->renderEach('view', [], 'iterator', 'foo'));
    }

    public function testRawStringsMayBeReturnedFromRenderEach(): void
    {
        $this->assertSame('foo', $this->getFactory()->renderEach('foo', [], 'item', 'raw|foo'));
    }

    public function testEnvironmentAddsExtensionWithCustomResolver(): void
    {
        $factory = $this->getFactory();

        $resolver = function () {
        };

        $factory->getFinder()->expects('addExtension')->with('foo');
        $factory->getEngineResolver()->expects('register')->with('bar', $resolver);
        $factory->getFinder()->expects('find')->with('view')->andReturn('path.foo');
        $engine = m::mock(Engine::class);
        $factory->getEngineResolver()->expects('resolve')->with('bar')->andReturn($engine);
        $factory->getDispatcher()->expects('hasListeners')->andReturn(false);
        $factory->setContainer(m::mock(Container::class));

        $factory->addExtension('foo', 'bar', $resolver);

        $view = $factory->make('view', ['data']);
        $this->assertSame($engine, $view->getEngine());
    }

    public function testAddingExtensionPrependsNotAppends(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('addExtension')->with('foo');

        $factory->addExtension('foo', 'bar');

        $extensions = $factory->getExtensions();
        $this->assertSame('bar', reset($extensions));
        $this->assertSame('foo', key($extensions));
    }

    public function testPrependedExtensionOverridesExistingExtensions(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('addExtension')->with('foo');
        $factory->getFinder()->expects('addExtension')->with('baz');

        $factory->addExtension('foo', 'bar');
        $factory->addExtension('baz', 'bar');

        $extensions = $factory->getExtensions();
        $this->assertSame('bar', reset($extensions));
        $this->assertSame('baz', key($extensions));
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessary(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: name', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: name', m::type('array'));

        $factory->setContainer(m::mock(Container::class));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('name');

        $factory->creator('name', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingNamespacedWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: namespaced::*', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: namespaced::my-package-view', m::type('array'));

        $factory->setContainer(m::mock(Container::class));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('namespaced::my-package-view');

        $factory->creator('namespaced::*', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingNamespacedNestedWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: namespaced::*', m::type(Closure::class));

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: welcome', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: namespaced::my-package-view', m::type('array'));

        $factory->setContainer(m::mock(Container::class));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('namespaced::my-package-view');

        $factory->creator(['namespaced::*', 'welcome'], fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: *', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: name', m::type('array'));

        $factory->setContainer(m::mock(Container::class));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('name');

        $factory->creator('*', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingNormalizedNames(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: components.button', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: components/button', m::type('array'));

        $factory->setContainer(m::mock(Container::class));

        $view = m::mock(View::class);
        $view->expects('name')
            ->andReturn('components/button');

        $factory->creator('components.button', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallComposerDoesDispatchEventsWhenIsNecessary(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: name', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: name', m::type('array'));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('name');

        $factory->composer('name', fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposerDoesDispatchEventsWhenIsNecessaryAndUsingTheArrayFormat(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: name', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: name', m::type('array'));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('name');

        $factory->composer(['name'], fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingNamespacedWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: namespaced::*', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: namespaced::my-package-view', m::type('array'));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('namespaced::my-package-view');

        $factory->composer('namespaced::*', fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingNamespacedNestedWildcards(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: namespaced::*', m::type(Closure::class));

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: welcome', m::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: namespaced::my-package-view', m::type('array'));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('namespaced::my-package-view');

        $factory->composer(['namespaced::*', 'welcome'], fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: *', m::type(Closure::class));

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: name', m::type('array'));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('name');

        $factory->composer('*', fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingNormalizedNames(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: components.button', m::type(Closure::class));

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: components/button', m::type('array'));

        $view = m::mock(View::class);
        $view->expects('name')->andReturn('components/button');

        $factory->composer('components.button', fn () => true);

        $factory->callComposer($view);
    }

    public function testComposersAreProperlyRegistered(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo', m::type(Closure::class));
        $callback = $factory->composer('foo', function () {
            return 'bar';
        });
        $callback = $callback[0];

        $this->assertSame('bar', $callback());
    }

    public function testComposersCanBeMassRegistered(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: bar', m::type(Closure::class));
        $factory->getDispatcher()->expects('listen')->with('composing: qux', m::type(Closure::class));
        $factory->getDispatcher()->expects('listen')->with('composing: foo', m::type(Closure::class));
        $composers = $factory->composers([
            'foo' => 'bar',
            'baz@baz' => ['qux', 'foo'],
        ]);

        $this->assertCount(3, $composers);
        $reflections = [
            new ReflectionFunction($composers[0]),
            new ReflectionFunction($composers[1]),
        ];
        $this->assertEquals(['class' => 'foo', 'method' => 'compose'], $reflections[0]->getStaticVariables());
        $this->assertEquals(['class' => 'baz', 'method' => 'baz'], $reflections[1]->getStaticVariables());
    }

    public function testClassCallbacks(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo', m::type(Closure::class));
        $container = m::mock(Container::class);
        $factory->setContainer($container);
        $composer = m::mock(stdClass::class);
        $container->expects('make')->with('FooComposer')->andReturn($composer);
        $composer->expects('compose')->with('view')->andReturn('composed');
        $callback = $factory->composer('foo', 'FooComposer');
        $callback = $callback[0];

        $this->assertSame('composed', $callback('view'));
    }

    public function testClassCallbacksWithMethods(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo', m::type(Closure::class));
        $container = m::mock(Container::class);
        $factory->setContainer($container);
        $composer = m::mock(stdClass::class);
        $container->expects('make')->with('FooComposer')->andReturn($composer);
        $composer->expects('doComposer')->with('view')->andReturn('composed');
        $callback = $factory->composer('foo', 'FooComposer@doComposer');
        $callback = $callback[0];

        $this->assertSame('composed', $callback('view'));
    }

    public function testCallComposerCallsProperEvent(): void
    {
        $factory = $this->getFactory();
        $view = m::mock(View::class);
        $dispatcher = m::mock(DispatcherContract::class);
        $factory->setDispatcher($dispatcher);

        $dispatcher->expects('listen')->with('composing: name', m::type(Closure::class));

        $view->expects('name')->andReturn('name');

        $factory->composer('name', fn () => true);

        $factory->getDispatcher()->expects('hasListeners')->andReturn(true);
        $factory->getDispatcher()->expects('dispatch')->with('composing: name', [$view]);

        $factory->callComposer($view);
    }

    public function testComposersAreRegisteredWithSlashAndDot(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo.bar', m::any())->times(2);
        $factory->composer('foo.bar', '');
        $factory->composer('foo/bar', '');
    }

    public function testRenderCountHandling(): void
    {
        $factory = $this->getFactory();
        $factory->incrementRender();
        $this->assertFalse($factory->doneRendering());
        $factory->decrementRender();
        $this->assertTrue($factory->doneRendering());
    }

    public function testYieldDefault(): void
    {
        $factory = $this->getFactory();
        $this->assertSame('hi', $factory->yieldContent('foo', 'hi'));
    }

    public function testYieldDefaultIsEscaped(): void
    {
        $factory = $this->getFactory();
        $this->assertSame('&lt;p&gt;hi&lt;/p&gt;', $factory->yieldContent('foo', '<p>hi</p>'));
    }

    public function testYieldDefaultViewIsNotEscapedTwice(): void
    {
        $factory = $this->getFactory();
        $view = m::mock(View::class);
        $view->expects('render')->andReturn('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;');
        $this->assertSame('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;', $factory->yieldContent('foo', $view));
    }

    public function testExistingSectionDoesNotRenderDefaultView(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->shouldReceive('hasListeners')->andReturn(false);
        $factory->startSection('foo', 'section');

        $engine = m::mock(Engine::class);
        $engine->allows('get')->andReturnUsing(function () use ($factory): string {
            $factory->startPush('default');
            echo 'unused';
            $factory->stopPush();

            return 'default';
        });

        $factory->incrementRender();

        try {
            $default = new View($factory, $engine, 'default', 'default.php');

            $this->assertSame('section', $factory->yieldContent('foo', $default));
            $this->assertSame('', $factory->yieldPushContent('default'));
        } finally {
            $factory->decrementRender();
            $factory->flushState();
        }
    }

    public function testBasicFragmentHandling(): void
    {
        $factory = $this->getFactory();
        $factory->startFragment('foo');
        echo 'hi';
        $this->assertSame('hi', $factory->stopFragment());
    }

    public function testBasicSectionHandling(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hi';
        $factory->stopSection();
        $this->assertSame('hi', $factory->yieldContent('foo'));
    }

    public function testBasicSectionDefault(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo', 'hi');
        $this->assertSame('hi', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testBasicSectionDefaultIsEscaped(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo', '<p>hi</p>');
        $this->assertSame('&lt;p&gt;hi&lt;/p&gt;', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testBasicSectionDefaultViewIsNotEscapedTwice(): void
    {
        $factory = $this->getFactory();
        $view = m::mock(View::class);
        $view->expects('render')->andReturn('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;');
        $factory->startSection('foo', $view);
        $this->assertSame('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;', $factory->getSections()['foo']);
        $this->assertSame('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testSectionExtending(): void
    {
        $factory = $this->getFactory();
        $placeholder = Factory::parentPlaceholder('foo');
        $factory->startSection('foo');
        echo 'hi ' . $placeholder;
        $factory->stopSection();
        $factory->startSection('foo');
        echo 'there';
        $factory->stopSection();
        $this->assertSame('hi there', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testSectionMultipleExtending(): void
    {
        $factory = $this->getFactory();
        $placeholder = Factory::parentPlaceholder('foo');
        $factory->startSection('foo');
        echo 'hello ' . $placeholder . ' nice to see you ' . $placeholder;
        $factory->stopSection();
        $factory->startSection('foo');
        echo 'my ' . $placeholder;
        $factory->stopSection();
        $factory->startSection('foo');
        echo 'friend';
        $factory->stopSection();
        $this->assertSame('hello my friend nice to see you my friend', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testComponentHandling(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->andReturn(__DIR__ . '/Fixtures/component.php');
        $factory->getEngineResolver()->expects('resolve')->andReturn(new PhpEngine(new Filesystem));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->andReturn(false);

        $factory->startComponent('component', ['name' => 'Taylor']);
        $factory->slot('title');
        $factory->slot('website', 'hypervel.com', []);
        echo 'title<hr>';
        $factory->endSlot();
        echo 'component';
        $contents = $factory->renderComponent();
        $this->assertSame('title<hr> component Taylor hypervel.com', $contents);
    }

    public function testComponentHandlingUsingViewObject(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->andReturn(__DIR__ . '/Fixtures/component.php');
        $factory->getEngineResolver()->expects('resolve')->andReturn(new PhpEngine(new Filesystem));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->andReturn(false);

        $factory->startComponent($factory->make('component'), ['name' => 'Taylor']);
        $factory->slot('title');
        $factory->slot('website', 'hypervel.com', []);
        echo 'title<hr>';
        $factory->endSlot();
        echo 'component';
        $contents = $factory->renderComponent();
        $this->assertSame('title<hr> component Taylor hypervel.com', $contents);
    }

    public function testComponentHandlingUsingClosure(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->andReturn(__DIR__ . '/Fixtures/component.php');
        $factory->getEngineResolver()->expects('resolve')->andReturn(new PhpEngine(new Filesystem));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->andReturn(false);
        $factory->startComponent(function (array $data) use ($factory): ViewContract {
            $this->assertArrayHasKey('name', $data);
            $this->assertSame('Taylor', $data['name']);

            return $factory->make('component');
        }, ['name' => 'Taylor']);
        $factory->slot('title');
        $factory->slot('website', 'hypervel.com', []);
        echo 'title<hr>';
        $factory->endSlot();
        echo 'component';
        $contents = $factory->renderComponent();
        $this->assertSame('title<hr> component Taylor hypervel.com', $contents);
    }

    public function testComponentHandlingUsingHtmlable(): void
    {
        $factory = $this->getFactory();
        $factory->startComponent(new HtmlString('hypervel.com'));
        $contents = $factory->renderComponent();
        $this->assertSame('hypervel.com', $contents);
    }

    public function testFlushStateResetsSlots(): void
    {
        $factory = $this->getFactory();

        $factory->slot('title');
        echo 'hypervel.com';
        $factory->endSlot();

        $factory->flushState();

        [$slots, $slotStack] = (fn (): array => [
            CoroutineContext::get(static::SLOTS_CONTEXT_KEY, []),
            CoroutineContext::get(static::SLOT_STACK_CONTEXT_KEY, []),
        ])->call($factory);

        $this->assertSame([], $slots);
        $this->assertSame([], $slotStack);
    }

    public function testTranslation(): void
    {
        $container = new ContainerInstance;
        $translator = m::mock(Translator::class);
        $translator->expects('get')->with('Foo', ['name' => 'taylor'])->andReturn('Bar');
        $container->instance('translator', $translator);
        $factory = $this->getFactory();
        $factory->setContainer($container);
        $factory->startTranslation(['name' => 'taylor']);
        echo 'Foo';
        $string = $factory->renderTranslation();

        $this->assertSame('Bar', $string);
    }

    public function testSingleStackPush(): void
    {
        $factory = $this->getFactory();
        $factory->startPush('foo');
        echo 'hi';
        $factory->stopPush();
        $this->assertSame('hi', $factory->yieldPushContent('foo'));
        $factory->flushStacks();
    }

    public function testMultipleStackPush(): void
    {
        $factory = $this->getFactory();
        $factory->startPush('foo');
        echo 'hi';
        $factory->stopPush();
        $factory->startPush('foo');
        echo ', Hello!';
        $factory->stopPush();
        $this->assertSame('hi, Hello!', $factory->yieldPushContent('foo'));
        $factory->flushStacks();
    }

    public function testSingleStackPrepend(): void
    {
        $factory = $this->getFactory();
        $factory->startPrepend('foo');
        echo 'hi';
        $factory->stopPrepend();
        $this->assertSame('hi', $factory->yieldPushContent('foo'));
        $factory->flushStacks();
    }

    public function testMultipleStackPrepend(): void
    {
        $factory = $this->getFactory();
        $factory->startPrepend('foo');
        echo ', Hello!';
        $factory->stopPrepend();
        $factory->startPrepend('foo');
        echo 'hi';
        $factory->stopPrepend();
        $this->assertSame('hi, Hello!', $factory->yieldPushContent('foo'));
        $factory->flushStacks();
    }

    public function testSessionAppending(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hi';
        $factory->appendSection();
        $factory->startSection('foo');
        echo 'there';
        $factory->appendSection();
        $this->assertSame('hithere', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testYieldSectionStopsAndYields(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hi';
        $this->assertSame('hi', $factory->yieldSection());
        $factory->flushSections();
    }

    public function testInjectStartsSectionWithContent(): void
    {
        $factory = $this->getFactory();
        $factory->inject('foo', 'hi');
        $this->assertSame('hi', $factory->yieldContent('foo'));
        $factory->flushSections();
    }

    public function testEmptyStringIsReturnedForNonSections(): void
    {
        $factory = $this->getFactory();
        $this->assertEmpty($factory->yieldContent('foo'));
    }

    public function testSectionFlushing(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hi';
        $factory->stopSection();

        $this->assertCount(1, $factory->getSections());

        $factory->flushSections();

        $this->assertCount(0, $factory->getSections());
    }

    public function testHasSection(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hi';
        $factory->stopSection();

        $this->assertTrue($factory->hasSection('foo'));
        $this->assertFalse($factory->hasSection('bar'));
    }

    public function testSectionMissing(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hello world';
        $factory->stopSection();

        $this->assertTrue($factory->sectionMissing('bar'));
        $this->assertFalse($factory->sectionMissing('foo'));
    }

    public function testGetSection(): void
    {
        $factory = $this->getFactory();
        $factory->startSection('foo');
        echo 'hi';
        $factory->stopSection();

        $this->assertSame('hi', $factory->getSection('foo'));
        $this->assertNull($factory->getSection('bar'));
        $this->assertSame('default', $factory->getSection('bar', 'default'));
    }

    public function testMakeWithSlashAndDot(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->times(2)->with('foo.bar')->andReturn('path.php');
        $factory->getEngineResolver()->expects('resolve')->times(2)->with('php')->andReturn(m::mock(Engine::class));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->andReturn(false);
        $factory->setContainer(m::mock(Container::class));
        $factory->make('foo/bar');
        $factory->make('foo.bar');
    }

    public function testNamespacedViewNamesAreNormalizedProperly(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->times(2)->with('vendor/package::foo.bar')->andReturn('path.php');
        $factory->getEngineResolver()->expects('resolve')->times(2)->with('php')->andReturn(m::mock(Engine::class));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->andReturn(false);
        $factory->setContainer(m::mock(Container::class));
        $factory->make('vendor/package::foo/bar');
        $factory->make('vendor/package::foo.bar');
    }

    public function testExceptionIsThrownForUnknownExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('view')->andReturn('view.foo');
        $factory->make('view');
    }

    public function testExceptionsInSectionsAreThrown(): void
    {
        $this->expectExceptionObject(new ErrorException('section exception message'));

        $engine = new CompilerEngine(m::mock(CompilerInterface::class), new Filesystem);
        $engine->getCompiler()->expects('getCompiledPath')->times(2)->andReturnUsing(function (string $path): string {
            return $path;
        });
        $engine->getCompiler()->expects('isExpired')->times(2)->andReturn(false);
        $factory = $this->getFactory();
        $factory->getEngineResolver()->expects('resolve')->times(2)->andReturn($engine);
        $factory->getFinder()->expects('find')->with('layout')->andReturn(__DIR__ . '/Fixtures/section-exception-layout.php');
        $factory->getFinder()->expects('find')->with('view')->andReturn(__DIR__ . '/Fixtures/section-exception.php');
        $factory->getDispatcher()->expects('hasListeners')->times(4); // 2 "creating" + 2 "composing"...

        $factory->make('view')->render();
    }

    public function testExtraStopSectionCallThrowsException(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Cannot end a section without first starting one.'));

        $factory = $this->getFactory();
        $factory->startSection('foo');
        $factory->stopSection();

        $factory->stopSection();
    }

    public function testExtraAppendSectionCallThrowsException(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Cannot end a section without first starting one.'));

        $factory = $this->getFactory();
        $factory->startSection('foo');
        $factory->stopSection();

        $factory->appendSection();
    }

    public function testAddingLoops(): void
    {
        $factory = $this->getFactory();

        $factory->addLoop([1, 2, 3]);

        $expectedLoop = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => 3,
            'count' => 3,
            'first' => true,
            'last' => false,
            'odd' => false,
            'even' => true,
            'depth' => 1,
            'parent' => null,
        ];

        $this->assertEquals([$expectedLoop], $factory->getLoopStack());

        $factory->addLoop([1, 2, 3, 4]);

        $secondExpectedLoop = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => 4,
            'count' => 4,
            'first' => true,
            'last' => false,
            'odd' => false,
            'even' => true,
            'depth' => 2,
            'parent' => (object) $expectedLoop,
        ];
        $this->assertEquals([$expectedLoop, $secondExpectedLoop], $factory->getLoopStack());

        $factory->popLoop();

        $this->assertEquals([$expectedLoop], $factory->getLoopStack());

        $factory->popLoop();
    }

    public function testAddingLoopDoesNotCloseGenerator(): void
    {
        $factory = $this->getFactory();

        $data = (new class {
            /**
             * Generate the loop's chunks.
             */
            public function generate(): Generator
            {
                for ($count = 0; $count < 3; ++$count) {
                    yield ['a', 'b'];
                }
            }
        })->generate();

        $factory->addLoop($data);

        foreach ($data as $chunk) {
            $this->assertEquals(['a', 'b'], $chunk);
        }

        $factory->popLoop();
    }

    public function testAddingUncountableLoop(): void
    {
        $factory = $this->getFactory();

        $factory->addLoop('');

        $expectedLoop = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => null,
            'count' => null,
            'first' => true,
            'last' => null,
            'odd' => false,
            'even' => true,
            'depth' => 1,
            'parent' => null,
        ];

        $this->assertEquals([$expectedLoop], $factory->getLoopStack());

        $factory->popLoop();
    }

    public function testAddingLazyCollection(): void
    {
        $factory = $this->getFactory();

        $factory->addLoop(new LazyCollection(function () {
            $this->fail('LazyCollection\'s generator should not have been called');
        }));

        $expectedLoop = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => null,
            'count' => null,
            'first' => true,
            'last' => null,
            'odd' => false,
            'even' => true,
            'depth' => 1,
            'parent' => null,
        ];

        $this->assertEquals([$expectedLoop], $factory->getLoopStack());

        $factory->popLoop();
    }

    public function testIncrementingLoopIndices(): void
    {
        $factory = $this->getFactory();

        $factory->addLoop([1, 2, 3, 4]);

        $factory->incrementLoopIndices();

        $this->assertEquals(1, $factory->getLoopStack()[0]['iteration']);
        $this->assertEquals(0, $factory->getLoopStack()[0]['index']);
        $this->assertEquals(3, $factory->getLoopStack()[0]['remaining']);
        $this->assertTrue($factory->getLoopStack()[0]['odd']);
        $this->assertFalse($factory->getLoopStack()[0]['even']);

        $factory->incrementLoopIndices();

        $this->assertEquals(2, $factory->getLoopStack()[0]['iteration']);
        $this->assertEquals(1, $factory->getLoopStack()[0]['index']);
        $this->assertEquals(2, $factory->getLoopStack()[0]['remaining']);
        $this->assertFalse($factory->getLoopStack()[0]['odd']);
        $this->assertTrue($factory->getLoopStack()[0]['even']);

        $factory->popLoop();
    }

    public function testReachingEndOfLoop(): void
    {
        $factory = $this->getFactory();

        $factory->addLoop([1, 2]);

        $factory->incrementLoopIndices();

        $factory->incrementLoopIndices();

        $this->assertTrue($factory->getLoopStack()[0]['last']);
    }

    public function testIncrementingLoopIndicesOfUncountable(): void
    {
        $factory = $this->getFactory();

        $factory->addLoop('');

        $factory->incrementLoopIndices();
        $factory->incrementLoopIndices();

        $this->assertEquals(2, $factory->getLoopStack()[0]['iteration']);
        $this->assertEquals(1, $factory->getLoopStack()[0]['index']);
        $this->assertFalse($factory->getLoopStack()[0]['first']);
        $this->assertNull($factory->getLoopStack()[0]['remaining']);
        $this->assertNull($factory->getLoopStack()[0]['last']);
    }

    public function testFailedRenderFlushesLoopState(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->shouldReceive('hasListeners')->andReturn(false);

        $engine = m::mock(Engine::class);
        $engine->expects('get')->andReturnUsing(function () use ($factory): never {
            $factory->addLoop([1]);

            throw new RuntimeException('render failed');
        });

        try {
            (new View($factory, $engine, 'failed', 'failed.php'))->render();
            $this->fail('The view should have failed to render.');
        } catch (RuntimeException $exception) {
            $this->assertSame('render failed', $exception->getMessage());
        }

        $this->assertSame([], $factory->getLoopStack());
    }

    public function testMacro(): void
    {
        $factory = $this->getFactory();
        $factory->macro('getFoo', function () {
            return 'Hello World';
        });
        $this->assertSame('Hello World', $factory->getFoo());
    }

    /**
     * Create a view factory with mocked dependencies.
     */
    protected function getFactory(): Factory
    {
        return new Factory(
            m::mock(EngineResolver::class),
            m::mock(ViewFinderInterface::class),
            m::mock(DispatcherContract::class)
        );
    }

    /**
     * Create the view factory's mocked dependencies.
     *
     * @return array{EngineResolver, ViewFinderInterface, DispatcherContract}
     */
    protected function getFactoryArgs(): array
    {
        return [
            m::mock(EngineResolver::class),
            m::mock(ViewFinderInterface::class),
            m::mock(DispatcherContract::class),
        ];
    }
}
