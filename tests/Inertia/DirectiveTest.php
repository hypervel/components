<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Inertia\Directive;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\Config;
use Hypervel\Tests\Inertia\Fixtures\FakeGateway;
use Hypervel\View\Compilers\BladeCompiler;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DirectiveTest extends TestCase
{
    private Filesystem|m\MockInterface $filesystem;

    protected BladeCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(Gateway::class, FakeGateway::class);
        $this->filesystem = m::mock(Filesystem::class);

        /** @var Filesystem $filesystem */
        $filesystem = $this->filesystem;
        $this->compiler = new BladeCompiler($filesystem, __DIR__ . '/cache/views');
        $this->compiler->directive('inertia', [Directive::class, 'compile']);
        $this->compiler->directive('inertiaHead', [Directive::class, 'compileHead']);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderView(string $contents, array $data = []): string
    {
        return Blade::render($contents, $data, true);
    }

    public function testInertiaDirectiveRendersTheRootElement(): void
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $html = '<script data-page="app" type="application/json">{"component":"Foo\/Bar","props":{"foo":"bar"},"url":"\/test","version":""}</script><div id="app"></div>';

        $this->assertSame($html, $this->renderView('@inertia', ['page' => self::EXAMPLE_PAGE_OBJECT]));
        $this->assertSame($html, $this->renderView('@inertia()', ['page' => self::EXAMPLE_PAGE_OBJECT]));
        $this->assertSame($html, $this->renderView('@inertia("")', ['page' => self::EXAMPLE_PAGE_OBJECT]));
        $this->assertSame($html, $this->renderView("@inertia('')", ['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testInertiaDirectiveRendersServerSideRenderedContentWhenEnabled(): void
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $this->assertSame(
            '<p>This is some example SSR content</p>',
            $this->renderView('@inertia', ['page' => self::EXAMPLE_PAGE_OBJECT])
        );
    }

    public function testInertiaDirectiveCanUseADifferentRootElementId(): void
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $html = '<script data-page="foo" type="application/json">{"component":"Foo\/Bar","props":{"foo":"bar"},"url":"\/test","version":""}</script><div id="foo"></div>';

        $this->assertSame($html, $this->renderView('@inertia(foo)', ['page' => self::EXAMPLE_PAGE_OBJECT]));
        $this->assertSame($html, $this->renderView("@inertia('foo')", ['page' => self::EXAMPLE_PAGE_OBJECT]));
        $this->assertSame($html, $this->renderView('@inertia("foo")', ['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testInertiaHeadDirectiveRendersNothing(): void
    {
        Config::set(['inertia.ssr.enabled' => false]);

        $this->assertEmpty($this->renderView('@inertiaHead', ['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testInertiaHeadDirectiveRendersServerSideRenderedHeadElementsWhenEnabled(): void
    {
        Config::set(['inertia.ssr.enabled' => true]);

        $this->assertSame(
            "<meta charset=\"UTF-8\" />\n<title inertia>Example SSR Title</title>\n",
            $this->renderView('@inertiaHead', ['page' => self::EXAMPLE_PAGE_OBJECT])
        );
    }

    public function testTheServerSideRenderingRequestIsDispatchedOnlyOncePerRequest(): void
    {
        Config::set(['inertia.ssr.enabled' => true]);
        $this->app->instance(Gateway::class, $gateway = new FakeGateway);

        $view = "<!DOCTYPE html>\n<html>\n<head>\n@inertiaHead\n</head>\n<body>\n@inertia\n</body>\n</html>";
        $expected = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\" />\n<title inertia>Example SSR Title</title>\n</head>\n<body>\n<p>This is some example SSR content</p></body>\n</html>";

        $this->assertSame(
            $expected,
            $this->renderView($view, ['page' => self::EXAMPLE_PAGE_OBJECT])
        );

        $this->assertSame(1, $gateway->times);
    }
}
