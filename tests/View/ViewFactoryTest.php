<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Closure;
use ErrorException;
use Generator;
use Hypervel\Container\Container as ContainerInstance;
use Hypervel\Context\CoroutineContext;
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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ReflectionFunction;
use RuntimeException;

class ViewFactoryTest extends TestCase
{
    use VerifiesDoubles;

    public function testCloneIsolatesFinderAndSharedEnvironment(): void
    {
        $factory = new Factory(
            Double::for(EngineResolver::class)->strict(),
            new FileViewFinder(new Filesystem, [__DIR__ . '/Fixtures']),
            Double::for(DispatcherContract::class)->strict()
        );

        $clone = clone $factory;
        $clone->replaceNamespace('foo', __DIR__ . '/Fixtures/namespaced');
        $clone->share('scoped', 'value');

        $this->assertSame(__DIR__ . '/Fixtures/namespaced/basic.php', $clone->getFinder()->find('foo::basic'));
        $this->assertSame($factory, $factory->shared('__env'));
        $this->assertSame($clone, $clone->shared('__env'));
        $this->assertNull($factory->shared('scoped'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('No hint path defined for [foo].');

        $factory->getFinder()->find('foo::basic');
    }

    public function testMakeCreatesNewViewInstanceWithProperPathAndEngine(): void
    {
        unset($_SERVER['__test.view']);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('view')->returns('path.php');
        $engine = Double::for(Engine::class)->strict();
        $factory->getEngineResolver()->expects('resolve')->with('php')->returns($engine);
        $factory->getFinder()->expects('addExtension')->with('php');
        $factory->setDispatcher($this->createEventDispatcher());
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
        $factory->getFinder()->expects('find')->with('foo')->throws(new InvalidArgumentException);
        $factory->getFinder()->expects('find')->with('bar')->returns('path.php');

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
        $factory->getFinder()->expects('find')->times(2)->with('view')->returns('path.php');
        $factory->getFinder()->expects('find')->with('bar')->throws(new InvalidArgumentException);
        $engine = Double::for(Engine::class)->strict();
        $factory->getEngineResolver()->expects('resolve')->with('php')->returns($engine);
        $factory->getFinder()->expects('addExtension')->with('php');
        $factory->setDispatcher($this->createEventDispatcher());
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
        $factory->getFinder()->expects('find')->times(2)->with('0')->returns('0.php');
        $factory->getEngineResolver()->expects('resolve')->with('php')->returns(Double::for(Engine::class)->strict());
        $factory->getDispatcher()->expects('hasListeners')->with('creating: 0')->returns(false);

        $this->assertSame('0', $factory->first(['0'])->name());
    }

    public function testFirstThrowsInvalidArgumentExceptionIfNoneFound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('view')->throws(new InvalidArgumentException);
        $factory->getFinder()->expects('find')->with('bar')->throws(new InvalidArgumentException);
        $factory->getFinder()->expects('addExtension')->with('php');
        $factory->addExtension('php', 'php');
        $factory->first(['bar', 'view'], ['foo' => 'bar'], ['baz' => 'boom']);
    }

    public function testRenderEachCreatesViewForEachItemInArray(): void
    {
        $factory = Double::for(Factory::class)->passthru(new Factory(...$this->getFactoryArgs()));
        $mockView1 = Double::for(ViewContract::class)->strict();
        $factory->expects('make')->with('foo', ['key' => 'bar', 'value' => 'baz'])->returns($mockView1);
        $mockView2 = Double::for(ViewContract::class)->strict();
        $factory->expects('make')->with('foo', ['key' => 'breeze', 'value' => 'boom'])->returns($mockView2);
        $mockView1->expects('render')->returns('dayle');
        $mockView2->expects('render')->returns('rees');

        $result = $factory->renderEach('foo', ['bar' => 'baz', 'breeze' => 'boom'], 'value');

        $this->assertSame('daylerees', $result);
    }

    public function testEmptyViewsCanBeReturnedFromRenderEach(): void
    {
        $factory = Double::for(Factory::class)->passthru(new Factory(...$this->getFactoryArgs()));
        $mockView = Double::for(ViewContract::class)->strict();
        $factory->expects('make')->with('foo')->returns($mockView);
        $mockView->expects('render')->returns('empty');

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
        $factory->getEngineResolver()->expects('register')->with('bar', Argument::same($resolver));
        $factory->getFinder()->expects('find')->with('view')->returns('path.foo');
        $engine = Double::for(Engine::class)->strict();
        $factory->getEngineResolver()->expects('resolve')->with('bar')->returns($engine);
        $factory->getDispatcher()->expects('hasListeners')->returns(false);

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
            ->with('creating: name', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: name', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('name');

        $factory->creator('name', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingNamespacedWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: namespaced::*', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: namespaced::my-package-view', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('namespaced::my-package-view');

        $factory->creator('namespaced::*', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingNamespacedNestedWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: namespaced::*', Argument::type(Closure::class));

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: welcome', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: namespaced::my-package-view', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('namespaced::my-package-view');

        $factory->creator(['namespaced::*', 'welcome'], fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: *', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: name', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('name');

        $factory->creator('*', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallCreatorsDoesDispatchEventsWhenIsNecessaryUsingNormalizedNames(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('creating: components.button', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('creating: components/button', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')
            ->returns('components/button');

        $factory->creator('components.button', fn () => true);

        $factory->callCreator($view);
    }

    public function testCallComposerDoesDispatchEventsWhenIsNecessary(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: name', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: name', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('name');

        $factory->composer('name', fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposerDoesDispatchEventsWhenIsNecessaryAndUsingTheArrayFormat(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: name', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: name', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('name');

        $factory->composer(['name'], fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingNamespacedWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: namespaced::*', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: namespaced::my-package-view', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('namespaced::my-package-view');

        $factory->composer('namespaced::*', fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingNamespacedNestedWildcards(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: namespaced::*', Argument::type(Closure::class));

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: welcome', Argument::type(Closure::class));

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: namespaced::my-package-view', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('namespaced::my-package-view');

        $factory->composer(['namespaced::*', 'welcome'], fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingWildcards(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: *', Argument::type(Closure::class));

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: name', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('name');

        $factory->composer('*', fn () => true);

        $factory->callComposer($view);
    }

    public function testCallComposersDoesDispatchEventsWhenIsNecessaryUsingNormalizedNames(): void
    {
        $factory = $this->getFactory();

        $factory->getDispatcher()->expects('hasListeners')->returns(true);

        $factory->getDispatcher()
            ->expects('listen')
            ->with('composing: components.button', Argument::type(Closure::class));

        $factory->getDispatcher()
            ->expects('dispatch')
            ->with('composing: components/button', Argument::type('array'));

        $view = Double::for(View::class)->strict();
        $view->expects('name')->returns('components/button');

        $factory->composer('components.button', fn () => true);

        $factory->callComposer($view);
    }

    public function testComposersAreProperlyRegistered(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo', Argument::type(Closure::class));
        $callback = $factory->composer('foo', function () {
            return 'bar';
        });
        $callback = $callback[0];

        $this->assertSame('bar', $callback());
    }

    public function testComposersCanBeMassRegistered(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: bar', Argument::type(Closure::class));
        $factory->getDispatcher()->expects('listen')->with('composing: qux', Argument::type(Closure::class));
        $factory->getDispatcher()->expects('listen')->with('composing: foo', Argument::type(Closure::class));
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
        $factory->getDispatcher()->expects('listen')->with('composing: foo', Argument::type(Closure::class));
        $factory->setContainer(new ContainerInstance);
        $callback = $factory->composer('foo', ViewComposerStub::class);
        $callback = $callback[0];

        $this->assertSame('view composed', $callback('view'));
    }

    public function testClassCallbacksWithMethods(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo', Argument::type(Closure::class));
        $factory->setContainer(new ContainerInstance);
        $callback = $factory->composer('foo', ViewComposerStub::class . '@doComposer');
        $callback = $callback[0];

        $this->assertSame('view do-composed', $callback('view'));
    }

    public function testCallComposerCallsProperEvent(): void
    {
        $factory = $this->getFactory();
        $view = Double::for(View::class)->strict();
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $factory->setDispatcher($dispatcher);

        $dispatcher->expects('listen')->with('composing: name', Argument::type(Closure::class));

        $view->expects('name')->returns('name');

        $factory->composer('name', fn () => true);

        $factory->getDispatcher()->expects('hasListeners')->returns(true);
        $factory->getDispatcher()->expects('dispatch')->with('composing: name', [$view]);

        $factory->callComposer($view);
    }

    public function testComposersAreRegisteredWithSlashAndDot(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->expects('listen')->with('composing: foo.bar', Argument::any())->times(2);
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
        $view = Double::for(View::class)->strict();
        $view->expects('render')->returns('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;');
        $this->assertSame('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;', $factory->yieldContent('foo', $view));
    }

    public function testExistingSectionDoesNotRenderDefaultView(): void
    {
        $factory = $this->getFactory();
        $factory->getDispatcher()->allows('hasListeners')->returns(false);
        $factory->startSection('foo', 'section');

        $engine = Double::for(Engine::class)->strict();
        $engine->allows('get')->resolves(function () use ($factory): string {
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
        $view = Double::for(View::class)->strict();
        $view->expects('render')->returns('<p>hi</p>&lt;p&gt;already escaped&lt;/p&gt;');
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
        $factory->getFinder()->expects('find')->returns(__DIR__ . '/Fixtures/component.php');
        $factory->getEngineResolver()->expects('resolve')->returns(new PhpEngine(new Filesystem));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->returns(false);

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
        $factory->getFinder()->expects('find')->returns(__DIR__ . '/Fixtures/component.php');
        $factory->getEngineResolver()->expects('resolve')->returns(new PhpEngine(new Filesystem));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->returns(false);

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
        $factory->getFinder()->expects('find')->returns(__DIR__ . '/Fixtures/component.php');
        $factory->getEngineResolver()->expects('resolve')->returns(new PhpEngine(new Filesystem));
        $factory->getDispatcher()->expects('hasListeners')->times(2)->returns(false);
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
        $translator = Double::for(Translator::class)->strict();
        $translator->expects('get')->with('Foo', ['name' => 'taylor'])->returns('Bar');
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
        $factory->getFinder()->expects('find')->times(2)->with('foo.bar')->returns('path.php');
        $factory->getEngineResolver()->expects('resolve')->times(2)->with('php')->returns(Double::for(Engine::class)->strict());
        $factory->getDispatcher()->expects('hasListeners')->times(2)->returns(false);
        $factory->make('foo/bar');
        $factory->make('foo.bar');
    }

    public function testNamespacedViewNamesAreNormalizedProperly(): void
    {
        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->times(2)->with('vendor/package::foo.bar')->returns('path.php');
        $factory->getEngineResolver()->expects('resolve')->times(2)->with('php')->returns(Double::for(Engine::class)->strict());
        $factory->getDispatcher()->expects('hasListeners')->times(2)->returns(false);
        $factory->make('vendor/package::foo/bar');
        $factory->make('vendor/package::foo.bar');
    }

    public function testExceptionIsThrownForUnknownExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory = $this->getFactory();
        $factory->getFinder()->expects('find')->with('view')->returns('view.foo');
        $factory->make('view');
    }

    public function testExceptionsInSectionsAreThrown(): void
    {
        $this->expectExceptionObject(new ErrorException('section exception message'));

        $engine = new CompilerEngine(Double::for(CompilerInterface::class)->strict(), new Filesystem);
        $engine->getCompiler()->expects('getCompiledPath')->times(2)->resolves(function (string $path): string {
            return $path;
        });
        $engine->getCompiler()->expects('isExpired')->times(2)->returns(false);
        $factory = $this->getFactory();
        $factory->getEngineResolver()->expects('resolve')->times(2)->returns($engine);
        $factory->getFinder()->expects('find')->with('layout')->returns(__DIR__ . '/Fixtures/section-exception-layout.php');
        $factory->getFinder()->expects('find')->with('view')->returns(__DIR__ . '/Fixtures/section-exception.php');
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
        $factory->getDispatcher()->allows('hasListeners')->returns(false);

        $engine = Double::for(Engine::class)->strict();
        $engine->expects('get')->resolves(function () use ($factory): never {
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
            Double::for(EngineResolver::class)->strict(),
            Double::for(ViewFinderInterface::class)->strict(),
            Double::for(DispatcherContract::class)->strict()
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
            Double::for(EngineResolver::class)->strict(),
            Double::for(ViewFinderInterface::class)->strict(),
            Double::for(DispatcherContract::class)->strict(),
        ];
    }
}

class ViewComposerStub
{
    /**
     * Compose the view.
     */
    public function compose(string $view): string
    {
        return $view . ' composed';
    }

    /**
     * Compose the view through a custom method.
     */
    public function doComposer(string $view): string
    {
        return $view . ' do-composed';
    }
}
