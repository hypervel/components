<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Context\CoroutineContext;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\Config;
use Hypervel\Tests\Inertia\Fixtures\FakeGateway;

/**
 * @internal
 * @coversNothing
 */
class ComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(Gateway::class, FakeGateway::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderView(string $contents, array $data = []): string
    {
        $state = CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);
        $state->page = $data['page'] ?? [];

        return Blade::render($contents, $data, true);
    }

    /**
     * Reset InertiaState between renders within the same test.
     */
    protected function resetInertiaState(): void
    {
        CoroutineContext::forget(InertiaState::CONTEXT_KEY);
    }

    public function testHeadComponentRendersFallbackSlotWhenSsrIsDisabled()
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $view = '<x-inertia::head><title>Fallback Title</title></x-inertia::head>';

        $this->assertStringContainsString(
            '<title>Fallback Title</title>',
            $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT])
        );
    }

    public function testHeadComponentRendersSsrHeadWhenSsrIsEnabled()
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $view = '<x-inertia::head><title>Fallback Title</title></x-inertia::head>';
        $rendered = $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertStringContainsString('<title inertia>Example SSR Title</title>', $rendered);
        $this->assertStringNotContainsString('<title>Fallback Title</title>', $rendered);
    }

    public function testAppComponentRendersClientSideDivWhenSsrIsDisabled()
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $view = '<x-inertia::app />';
        $rendered = $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertStringContainsString('<div id="app"></div>', $rendered);
        $this->assertStringContainsString('data-page="app"', $rendered);
    }

    public function testAppComponentRendersSsrBodyWhenSsrIsEnabled()
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $view = '<x-inertia::app />';

        $this->assertSame(
            '<p>This is some example SSR content</p>',
            trim($this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT]))
        );
    }

    public function testAppComponentAcceptsCustomId()
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $view = '<x-inertia::app id="custom" />';
        $rendered = $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertStringContainsString('<div id="custom"></div>', $rendered);
        $this->assertStringContainsString('data-page="custom"', $rendered);
    }

    public function testSsrIsOnlyDispatchedOnceWithComponents()
    {
        Config::set(['inertia.ssr.enabled' => true]);
        $this->app->instance(Gateway::class, $gateway = new FakeGateway);

        $view = '<x-inertia::head><title>Fallback</title></x-inertia::head><x-inertia::app />';
        $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertSame(1, $gateway->times);
    }

    public function testAppComponentMatchesDirectiveOutputWhenSsrIsDisabled()
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $directive = $this->renderView('@inertia', ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->resetInertiaState();

        $component = trim($this->renderView('<x-inertia::app />', ['page' => self::EXAMPLE_PAGE_OBJECT]));

        $this->assertSame($directive, $component);
    }

    public function testAppComponentMatchesDirectiveOutputWhenSsrIsEnabled()
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $directive = $this->renderView('@inertia', ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->resetInertiaState();

        $component = trim($this->renderView('<x-inertia::app />', ['page' => self::EXAMPLE_PAGE_OBJECT]));

        $this->assertSame($directive, $component);
    }

    public function testAppComponentWithCustomIdMatchesDirectiveOutput()
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $directive = $this->renderView('@inertia("foo")', ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->resetInertiaState();

        $component = trim($this->renderView('<x-inertia::app id="foo" />', ['page' => self::EXAMPLE_PAGE_OBJECT]));

        $this->assertSame($directive, $component);
    }

    public function testHeadComponentWithoutSlotMatchesDirectiveOutputWhenSsrIsDisabled()
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $directive = $this->renderView('@inertiaHead', ['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->resetInertiaState();

        $component = trim($this->renderView('<x-inertia::head />', ['page' => self::EXAMPLE_PAGE_OBJECT]));

        $this->assertSame($directive, $component);
    }

    public function testHeadComponentWithoutSlotMatchesDirectiveOutputWhenSsrIsEnabled()
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $directive = trim($this->renderView('@inertiaHead', ['page' => self::EXAMPLE_PAGE_OBJECT]));

        $this->resetInertiaState();

        $component = trim($this->renderView('<x-inertia::head />', ['page' => self::EXAMPLE_PAGE_OBJECT]));

        $this->assertSame($directive, $component);
    }

    public function testComponentsDoNotCreateCachedViewFilesPerRequest()
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $viewCachePath = $this->app['config']['view.compiled'];
        $view = '<x-inertia::head><title>Fallback</title></x-inertia::head><x-inertia::app />';

        $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT]);
        $cachedViews = glob($viewCachePath . '/*.php');

        $this->resetInertiaState();

        $this->renderView($view, ['page' => ['component' => 'Different', 'props' => ['foo' => 'bar']]]);
        $this->assertSame($cachedViews, glob($viewCachePath . '/*.php'));
    }

    public function testInertiaStateDoesNotLeakBetweenRequests()
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $state1 = CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);
        $state1->page = self::EXAMPLE_PAGE_OBJECT;
        $state1->ssrDispatched = true;
        $state1->ssrResponse = app(Gateway::class)->dispatch($state1->page);

        $this->assertNotNull($state1->ssrResponse);

        // Simulate request boundary by clearing Context state
        $this->resetInertiaState();

        $state2 = CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);

        $this->assertNotSame($state1, $state2);
        $this->assertNull($state2->ssrResponse);
        $this->assertFalse($state2->ssrDispatched);
    }
}
