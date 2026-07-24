<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use ArrayAccess;
use BadMethodCallException;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Renderable;
use Hypervel\Contracts\View\Engine;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\TestCase;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory;
use Hypervel\View\View;
use Hypervel\View\ViewFinderInterface;
use Mockery as m;

class ViewTest extends TestCase
{
    public function testDataCanBeSetOnView(): void
    {
        $view = $this->getView();
        $view->with('foo', 'bar');
        $view->with(['baz' => 'boom']);
        $this->assertEquals(['foo' => 'bar', 'baz' => 'boom'], $view->getData());

        $view = $this->getView();
        $view->withFoo('bar')->withBaz('boom');
        $this->assertEquals(['foo' => 'bar', 'baz' => 'boom'], $view->getData());
    }

    public function testRenderProperlyRendersView(): void
    {
        $view = $this->getView(['foo' => 'bar']);
        $view->getFactory()->shouldReceive('incrementRender')->once()->ordered();
        $view->getFactory()->shouldReceive('callComposer')->once()->ordered()->with($view);
        $view->getFactory()->shouldReceive('notifyRendering')->once()->ordered()->with($view);
        $view->getFactory()->shouldReceive('mergeSharedData')->once()->with(['foo' => 'bar'])->andReturn(['foo' => 'bar', 'shared' => 'foo']);
        $view->getEngine()->shouldReceive('get')->once()->with('path', ['foo' => 'bar', 'shared' => 'foo'])->andReturn('contents');
        $view->getFactory()->shouldReceive('decrementRender')->once()->ordered();
        $view->getFactory()->shouldReceive('flushStateIfDoneRendering')->once();

        $callback = function (View $rendered, string $contents) use ($view): ?string {
            $this->assertEquals($view, $rendered);
            $this->assertSame('contents', $contents);

            return null;
        };

        $this->assertSame('contents', $view->render($callback));
    }

    public function testRenderHandlingCallbackReturnValues(): void
    {
        $view = $this->getView();
        $view->getFactory()->shouldReceive('incrementRender');
        $view->getFactory()->shouldReceive('callComposer');
        $view->getFactory()->shouldReceive('notifyRendering')->with($view);
        $view->getFactory()->shouldReceive('mergeSharedData')->with([])->andReturn(['shared' => 'foo']);
        $view->getEngine()->shouldReceive('get')->andReturn('contents');
        $view->getFactory()->shouldReceive('decrementRender');
        $view->getFactory()->shouldReceive('flushStateIfDoneRendering');

        $this->assertSame('new contents', $view->render(function (): string {
            return 'new contents';
        }));

        $this->assertSame('', $view->render(function (): string {
            return '';
        }));

        $this->assertSame('0', $view->render(function (): string {
            return '0';
        }));

        $this->assertSame('contents', $view->render(function (): ?string {
            return null;
        }));
    }

    public function testRenderSectionsReturnsEnvironmentSections(): void
    {
        $view = new TestView(
            $factory = m::mock(Factory::class),
            m::mock(Engine::class),
            'view',
            'path',
            [],
        );

        $factory->shouldReceive('getSections')->with()->once()->andReturn($sections = ['foo' => 'bar']);
        $factory->shouldReceive('flushStateIfDoneRendering')->with()->once();

        $this->assertEquals($sections, $view->renderSections());
    }

    public function testSectionsAreNotFlushedWhenNotDoneRendering(): void
    {
        $view = $this->getView(['foo' => 'bar']);
        $view->getFactory()->shouldReceive('incrementRender')->twice();
        $view->getFactory()->shouldReceive('callComposer')->twice()->with($view);
        $view->getFactory()->shouldReceive('notifyRendering')->twice()->with($view);
        $view->getFactory()->shouldReceive('mergeSharedData')->twice()->with(['foo' => 'bar'])->andReturn(['foo' => 'bar', 'shared' => 'foo']);
        $view->getEngine()->shouldReceive('get')->twice()->with('path', ['foo' => 'bar', 'shared' => 'foo'])->andReturn('contents');
        $view->getFactory()->shouldReceive('decrementRender')->twice();
        $view->getFactory()->shouldReceive('flushStateIfDoneRendering')->twice();

        $this->assertSame('contents', $view->render());
        $this->assertSame('contents', (string) $view);
    }

    public function testViewNestBindsASubView(): void
    {
        $view = $this->getView();
        $view->getFactory()->shouldReceive('make')->once()->with('foo', ['data']);
        $result = $view->nest('key', 'foo', ['data']);

        $this->assertInstanceOf(View::class, $result);
    }

    public function testViewAcceptsArrayableImplementations(): void
    {
        $arrayable = m::mock(Arrayable::class);
        $arrayable->shouldReceive('toArray')->once()->andReturn(['foo' => 'bar', 'baz' => ['qux', 'corge']]);

        $view = $this->getView($arrayable);

        $this->assertSame('bar', $view->foo);
        $this->assertEquals(['qux', 'corge'], $view->baz);
    }

    public function testViewGettersSetters(): void
    {
        $view = $this->getView(['foo' => 'bar']);
        $this->assertSame('view', $view->name());
        $this->assertSame('path', $view->getPath());
        $data = $view->getData();
        $this->assertSame('bar', $data['foo']);
        $view->setPath('newPath');
        $this->assertSame('newPath', $view->getPath());
    }

    public function testViewArrayAccess(): void
    {
        $view = $this->getView(['foo' => 'bar']);
        $this->assertInstanceOf(ArrayAccess::class, $view);
        $this->assertTrue($view->offsetExists('foo'));
        $this->assertSame('bar', $view->offsetGet('foo'));
        $view->offsetSet('foo', 'baz');
        $this->assertSame('baz', $view->offsetGet('foo'));
        $view->offsetUnset('foo');
        $this->assertFalse($view->offsetExists('foo'));
    }

    public function testViewConstructedWithObjectData(): void
    {
        $view = $this->getView(new DataObjectStub);
        $this->assertInstanceOf(ArrayAccess::class, $view);
        $this->assertTrue($view->offsetExists('foo'));
        $this->assertSame('bar', $view->offsetGet('foo'));
        $view->offsetSet('foo', 'baz');
        $this->assertSame('baz', $view->offsetGet('foo'));
        $view->offsetUnset('foo');
        $this->assertFalse($view->offsetExists('foo'));
    }

    public function testViewMagicMethods(): void
    {
        $view = $this->getView(['foo' => 'bar']);
        $this->assertTrue(isset($view->foo));
        $this->assertSame('bar', $view->foo);
        $view->foo = 'baz';
        $this->assertSame('baz', $view->foo);
        $this->assertEquals($view['foo'], $view->foo);
        unset($view->foo);
        $this->assertFalse(isset($view->foo));
        $this->assertFalse($view->offsetExists('foo'));
    }

    public function testViewBadMethod(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method Hypervel\View\View::badMethodCall does not exist.');

        $view = $this->getView();
        $view->badMethodCall();
    }

    public function testViewGatherDataWithRenderable(): void
    {
        $view = $this->getView();
        $view->getFactory()->shouldReceive('incrementRender')->once()->ordered();
        $view->getFactory()->shouldReceive('callComposer')->once()->ordered()->with($view);
        $view->getFactory()->shouldReceive('notifyRendering')->once()->ordered()->with($view);
        $view->getEngine()->shouldReceive('get')->once()->andReturn('contents');
        $view->getFactory()->shouldReceive('decrementRender')->once()->ordered();
        $view->getFactory()->shouldReceive('flushStateIfDoneRendering')->once();

        $view->renderable = m::mock(Renderable::class);
        $view->renderable->shouldReceive('render')->once()->andReturn('text');
        $view->getFactory()->shouldReceive('mergeSharedData')->once()->with(['renderable' => $view->renderable])->andReturn([
            'shared' => 'foo',
            'renderable' => $view->renderable,
        ]);
        $this->assertSame('contents', $view->render());
    }

    public function testViewRenderSections(): void
    {
        $view = $this->getView();
        $view->getFactory()->shouldReceive('incrementRender')->once()->ordered();
        $view->getFactory()->shouldReceive('callComposer')->once()->ordered()->with($view);
        $view->getFactory()->shouldReceive('notifyRendering')->once()->ordered()->with($view);
        $view->getFactory()->shouldReceive('mergeSharedData')->once()->with([])->andReturn(['shared' => 'foo']);
        $view->getEngine()->shouldReceive('get')->once()->andReturn('contents');
        $view->getFactory()->shouldReceive('decrementRender')->once()->ordered();
        $view->getFactory()->shouldReceive('flushStateIfDoneRendering')->once();

        $view->getFactory()->shouldReceive('getSections')->once()->andReturn(['foo', 'bar']);
        $sections = $view->renderSections();
        $this->assertSame('foo', $sections[0]);
        $this->assertSame('bar', $sections[1]);
    }

    public function testWithErrors(): void
    {
        $view = $this->getView();
        $errors = ['foo' => 'bar', 'qu' => 'ux'];
        $this->assertSame($view, $view->withErrors($errors));
        $this->assertInstanceOf(ViewErrorBag::class, $view->errors);
        $foo = $view->errors->get('foo');
        $this->assertSame('bar', $foo[0]);
        $qu = $view->errors->get('qu');
        $this->assertSame('ux', $qu[0]);
        $data = ['foo' => 'baz'];
        $this->assertSame($view, $view->withErrors(new MessageBag($data)));
        $foo = $view->errors->get('foo');
        $this->assertSame('baz', $foo[0]);
        $foo = $view->errors->getBag('default')->get('foo');
        $this->assertSame('baz', $foo[0]);
        $this->assertSame($view, $view->withErrors(new MessageBag($data), 'login'));
        $foo = $view->errors->getBag('login')->get('foo');
        $this->assertSame('baz', $foo[0]);
    }

    public function testRenderingObserverIsCalled(): void
    {
        $factory = new Factory(
            m::mock(EngineResolver::class),
            m::mock(ViewFinderInterface::class),
            $events = m::mock(Dispatcher::class),
        );

        $observedView = null;
        $factory->observeRendering(function ($view) use (&$observedView) {
            $observedView = $view;
        });

        $engine = m::mock(Engine::class);
        $engine->shouldReceive('get')->once()->andReturn('contents');
        $events->shouldReceive('hasListeners')->andReturn(false);

        $view = new View($factory, $engine, 'test', 'path');
        $view->render();

        $this->assertSame($view, $observedView);
    }

    public function testRenderingObserversNotClearedByFlushState(): void
    {
        $factory = new Factory(
            m::mock(EngineResolver::class),
            m::mock(ViewFinderInterface::class),
            $events = m::mock(Dispatcher::class),
        );

        $observerCalled = false;
        $factory->observeRendering(function () use (&$observerCalled) {
            $observerCalled = true;
        });

        $factory->flushState();

        $engine = m::mock(Engine::class);
        $engine->shouldReceive('get')->once()->andReturn('contents');
        $events->shouldReceive('hasListeners')->andReturn(false);

        $view = new View($factory, $engine, 'test', 'path');
        $view->render();

        $this->assertTrue($observerCalled);
    }

    public function testMultipleRenderingObserversAreAllCalled(): void
    {
        $factory = new Factory(
            m::mock(EngineResolver::class),
            m::mock(ViewFinderInterface::class),
            $events = m::mock(Dispatcher::class),
        );

        $order = [];
        $factory->observeRendering(function () use (&$order) {
            $order[] = 'first';
        });
        $factory->observeRendering(function () use (&$order) {
            $order[] = 'second';
        });

        $engine = m::mock(Engine::class);
        $engine->shouldReceive('get')->once()->andReturn('contents');
        $events->shouldReceive('hasListeners')->andReturn(false);

        $view = new View($factory, $engine, 'test', 'path');
        $view->render();

        $this->assertSame(['first', 'second'], $order);
    }

    protected function getView(mixed $data = []): View
    {
        return new View(
            m::mock(Factory::class),
            m::mock(Engine::class),
            'view',
            'path',
            $data
        );
    }
}

class DataObjectStub
{
    public string $foo = 'bar';
}

class TestView extends View
{
    protected function renderContents(): string
    {
        return '';
    }
}
