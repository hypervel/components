<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Closure;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\HeadManager;
use Hypervel\Head\Inertia\ShareHead;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\Ssr\ConfiguresSsrRequests;
use Hypervel\Inertia\Ssr\DisablesSsr;
use Hypervel\Inertia\Ssr\ExcludesSsrPaths;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\HasHealthCheck;
use Hypervel\Inertia\Ssr\Response as SsrResponse;
use Hypervel\Inertia\Support\Header;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\Fixtures\ResolvesHttpKernelEarly;
use Hypervel\Tests\Head\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

// REMOVED: LivewireTest's navigation case and livewire-layout fixture; Hypervel supports Blade and Inertia.

class InertiaTest extends TestCase
{
    use ResolvesHttpKernelEarly;

    public function testRegistersTheShareHeadMiddlewareWhenTheHttpKernelWasResolvedBeforeThePackage(): void
    {
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(ShareHead::class));
    }

    public function testSharesRenderedHeadElementsWithInertiaPageObjects(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Acme', suffix: ' - Acme'));

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard', [
            'user' => 'Taylor',
        ]))->withHead(
            title: 'Dashboard',
            description: 'Your application overview.',
        );

        $response = $this->get('/dashboard', [Header::INERTIA => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'Dashboard')
            ->assertJsonPath('props.user', 'Taylor');

        $elements = $response->json('props.' . HeadManager::INERTIA_PROP);

        $this->assertIsArray($elements);
        $this->assertContains('<title data-inertia="title">Dashboard - Acme</title>', $elements);
        $this->assertContains('<meta data-inertia="description" name="description" content="Your application overview.">', $elements);
    }

    public function testRestoresSharedHeadElementsAfterInertiaFlushesItsSharedProps(): void
    {
        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))
            ->withHead(title: 'Dashboard');

        $headers = [Header::INERTIA => 'true'];

        $this->get('/dashboard', $headers)
            ->assertOk()
            ->assertJsonPath(
                'props.' . HeadManager::INERTIA_PROP . '.0',
                '<title data-inertia="title">Dashboard</title>',
            );

        Inertia::flushShared();

        $this->get('/dashboard', $headers)
            ->assertOk()
            ->assertJsonPath(
                'props.' . HeadManager::INERTIA_PROP . '.0',
                '<title data-inertia="title">Dashboard</title>',
            );
    }

    public function testSharesStableSemanticInertiaKeysForEachHeadElement(): void
    {
        Route::get('/lean', fn (): Response => Inertia::render('Page'))->withHead(
            description: 'Lean page.',
        );

        Route::get('/rich', fn (): Response => Inertia::render('Page'))->withHead(
            title: 'Rich page',
            description: 'Rich page.',
            preload: [['href' => '/fonts/inter.woff2', 'as' => 'font']],
            ogImage: 'https://example.com/rich.jpg',
        );

        $lean = $this->get('/lean', [Header::INERTIA => 'true'])->json('props.' . HeadManager::INERTIA_PROP);
        $rich = $this->get('/rich', [Header::INERTIA => 'true'])->json('props.' . HeadManager::INERTIA_PROP);

        $this->assertContains('<meta data-inertia="description" name="description" content="Lean page.">', $lean);
        $this->assertContains('<title data-inertia="title">Rich page</title>', $rich);
        $this->assertContains('<meta data-inertia="description" name="description" content="Rich page.">', $rich);

        foreach ([...$lean, ...$rich] as $element) {
            $this->assertStringContainsString(' data-inertia="', $element);
        }
    }

    public function testKeepsHeadElementsOffTheWireDuringInertiaPartialReloads(): void
    {
        $resolutions = 0;
        Head::resolveDefaultsUsing(function (HeadBuilder $head) use (&$resolutions): void {
            ++$resolutions;
            $head->description('Runtime metadata');
        });

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard', [
            'user' => 'Taylor',
        ]))->withHead(title: 'Dashboard');

        $this->get('/dashboard', [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'Dashboard',
            Header::PARTIAL_ONLY => 'user',
        ])
            ->assertOk()
            ->assertJsonPath('props.user', 'Taylor')
            ->assertJsonMissingPath('props.' . HeadManager::INERTIA_PROP);

        $this->assertSame(0, $resolutions);
    }

    public function testKeepsInertiaGlobalsOutOfInertiaPageObjects(): void
    {
        Head::inertiaGlobals(fn (HeadBuilder $head): HeadBuilder => $head
            ->viewport('width=device-width, initial-scale=1')
            ->icon('/favicon.svg', type: 'image/svg+xml'));

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            title: 'Dashboard',
            description: 'Dashboard overview.',
        );

        $elements = $this->get('/dashboard', [Header::INERTIA => 'true'])
            ->assertOk()
            ->json('props.' . HeadManager::INERTIA_PROP);

        $this->assertContains('<title data-inertia="title">Dashboard</title>', $elements);
        $this->assertContains('<meta data-inertia="description" name="description" content="Dashboard overview.">', $elements);

        $html = implode("\n", $elements);
        $this->assertStringNotContainsString('data-inertia="viewport"', $html);
        $this->assertStringNotContainsString('data-inertia="link:icon', $html);
    }

    public function testRendersInertiaGlobalsAsStaticTagsAndPageTagsAsInertiaManagedTagsInTheInertiaRootView(): void
    {
        Head::inertiaGlobals(fn (HeadBuilder $head): HeadBuilder => $head
            ->viewport('width=device-width, initial-scale=1')
            ->icon('/favicon.svg', type: 'image/svg+xml'));

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            title: 'Dashboard',
            description: 'Dashboard overview.',
        );

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
            ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
            ->assertSee('<title data-inertia="title">Dashboard</title>', false)
            ->assertSee('<meta data-inertia="description" name="description" content="Dashboard overview.">', false)
            ->assertDontSee('<meta data-inertia="viewport"', false)
            ->assertDontSee('<link data-inertia="link:icon', false);
    }

    public function testRendersTheFullInertiaDocumentInTheLegacyDirectiveRootTemplate(): void
    {
        Inertia::setRootView('app-directives');

        Head::inertiaGlobals(fn (HeadBuilder $head): HeadBuilder => $head
            ->viewport('width=device-width, initial-scale=1'));

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            title: 'Dashboard',
            description: 'Dashboard overview.',
        );

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<title data-inertia="title">Dashboard</title>'));
        $this->assertSame(1, substr_count($html, '<meta data-inertia="description" name="description" content="Dashboard overview.">'));
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
    }

    public function testPreservesTheDecoratedInertiaSsrGatewayCapabilities(): void
    {
        $gateway = new class implements ConfiguresSsrRequests, DisablesSsr, ExcludesSsrPaths, Gateway, HasHealthCheck {
            public Closure|bool|null $disabled = null;

            /** @var array<int, string> */
            public array $except = [];

            public ?Closure $configurator = null;

            public bool $healthy = true;

            /**
             * Return no SSR response.
             *
             * @param array<string, mixed> $page
             */
            public function dispatch(array $page): ?SsrResponse
            {
                return null;
            }

            /**
             * Record the SSR disabling condition.
             */
            public function disable(Closure|bool $condition): void
            {
                $this->disabled = $condition;
            }

            /**
             * Record excluded SSR paths.
             *
             * @param array<int, string>|string $paths
             */
            public function except(array|string $paths): void
            {
                $this->except = [
                    ...$this->except,
                    ...(is_array($paths) ? $paths : [$paths]),
                ];
            }

            /**
             * Record the request configurator.
             */
            public function configureRequestUsing(?Closure $callback = null): void
            {
                $this->configurator = $callback;
            }

            /**
             * Return the configured health result.
             */
            public function isHealthy(): bool
            {
                return $this->healthy;
            }
        };

        $this->app->bind(Gateway::class, fn (): Gateway => $gateway);

        $decorated = $this->app->make(Gateway::class);
        $condition = fn (): bool => true;

        $this->assertInstanceOf(DisablesSsr::class, $decorated);
        $this->assertInstanceOf(ExcludesSsrPaths::class, $decorated);
        $this->assertInstanceOf(HasHealthCheck::class, $decorated);
        $this->assertInstanceOf(ConfiguresSsrRequests::class, $decorated);

        Inertia::disableSsr($condition);
        Inertia::withoutSsr(['/admin', '/internal']);

        $this->assertSame($condition, $gateway->disabled);
        $this->assertSame(['/admin', '/internal'], $gateway->except);
        $this->assertTrue($decorated->isHealthy());
        $gateway->healthy = false;
        $this->assertFalse($decorated->isHealthy());
        $this->assertNull($decorated->dispatch([]));

        $configurator = static function (): void {};
        Inertia::configureSsrRequestUsing($configurator);
        $this->assertSame($configurator, $gateway->configurator);
        Inertia::configureSsrRequestUsing(null);
        $this->assertNull($gateway->configurator);
    }

    #[DataProvider('unsupportedCapabilities')]
    public function testReportsWhenTheDecoratedInertiaSsrGatewayLacksCapabilities(string $capability, string $message): void
    {
        $this->app->bind(Gateway::class, fn (): Gateway => new class implements Gateway {
            /**
             * Return no SSR response.
             *
             * @param array<string, mixed> $page
             */
            public function dispatch(array $page): ?SsrResponse
            {
                return null;
            }
        });

        $decorated = $this->app->make(Gateway::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        match ($capability) {
            'disable' => Inertia::disableSsr(),
            'except' => Inertia::withoutSsr('/admin'),
            'configure' => Inertia::configureSsrRequestUsing(),
            'health' => $decorated->isHealthy(),
        };
    }

    /**
     * Supply unsupported gateway capabilities and their diagnostics.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedCapabilities(): iterable
    {
        yield 'disable' => ['disable', 'The configured SSR gateway does not support disabling server-side rendering conditionally.'];
        yield 'paths' => ['except', 'The configured SSR gateway does not support excluding paths from server-side rendering.'];
        yield 'request configuration' => ['configure', 'The configured SSR gateway does not support configuring server-side rendering requests.'];
        yield 'health check' => ['health', 'The configured SSR gateway does not support health checks.'];
    }

    public function testDeduplicatesHypervelHeadFromTheInertiaSsrHeadWhilePreservingOtherSsrElements(): void
    {
        $this->app->bind(Gateway::class, fn (): Gateway => new class implements Gateway {
            /**
             * Render page-managed and client-only SSR elements.
             *
             * @param array<string, mixed> $page
             */
            public function dispatch(array $page): ?SsrResponse
            {
                return new SsrResponse(
                    implode("\n", [
                        ...$page['props'][HeadManager::INERTIA_PROP],
                        '<meta data-inertia="client-only" name="client-only" content="Preserved">',
                    ]),
                    '<div id="app">SSR BODY</div>',
                );
            }
        });

        Head::inertiaGlobals(fn (HeadBuilder $head): HeadBuilder => $head
            ->viewport('width=device-width, initial-scale=1'));

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            title: 'Dashboard',
            description: 'Dashboard overview.',
        );

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<title data-inertia="title">Dashboard</title>'));
        $this->assertSame(1, substr_count($html, '<meta data-inertia="description" name="description" content="Dashboard overview.">'));
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertSame(1, substr_count($html, '<meta data-inertia="client-only" name="client-only" content="Preserved">'));
        $this->assertStringContainsString('<div id="app">SSR BODY</div>', $html);
    }

    public function testDeduplicatesTheSsrHeadEvenWhenTheInertiaHeadComponentRendersBeforeTheDirective(): void
    {
        $this->app->bind(Gateway::class, fn (): Gateway => new class implements Gateway {
            /**
             * Render page-managed and client-only SSR elements.
             *
             * @param array<string, mixed> $page
             */
            public function dispatch(array $page): ?SsrResponse
            {
                return new SsrResponse(
                    implode("\n", [
                        ...$page['props'][HeadManager::INERTIA_PROP],
                        '<meta data-inertia="client-only" name="client-only" content="Preserved">',
                    ]),
                    '<div id="app">SSR BODY</div>',
                );
            }
        });

        Inertia::setRootView('app-inertia-head-first');

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            title: 'Dashboard',
            description: 'Dashboard overview.',
        );

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<title data-inertia="title">Dashboard</title>'));
        $this->assertSame(1, substr_count($html, '<meta data-inertia="description" name="description" content="Dashboard overview.">'));
        $this->assertSame(1, substr_count($html, '<meta data-inertia="client-only" name="client-only" content="Preserved">'));
        $this->assertStringContainsString('<div id="app">SSR BODY</div>', $html);
    }

    public function testRendersInertiaManagedTagsWhenTheDirectiveRunsOutsideTheRootViewScope(): void
    {
        Inertia::setRootView('app-nested');

        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            title: 'Dashboard',
        );

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('<title data-inertia="title">Dashboard</title>', false);
    }

    public function testPreservesTheWrappedResponseAndUsesTheCustomHeadProp(): void
    {
        Head::inertia('_head');
        $title = '<title data-inertia="title">Page</title>';
        $client = '<meta data-inertia="client-only" name="client-only" content="Preserved">';
        $response = new SsrResponse($title . $client, '<main>Body</main>');
        $gateway = new class($response) implements Gateway {
            /**
             * Retain the original immutable SSR response.
             */
            public function __construct(public readonly SsrResponse $response)
            {
            }

            /**
             * Return the retained response.
             *
             * @param array<string, mixed> $page
             */
            public function dispatch(array $page): ?SsrResponse
            {
                return $this->response;
            }
        };
        $this->app->bind(Gateway::class, fn (): Gateway => $gateway);

        $rendered = $this->app->make(Gateway::class)->dispatch(['props' => ['_head' => [$title]]]);

        $this->assertNotSame($response, $rendered);
        $this->assertSame($title . $client, $response->head);
        $this->assertSame($client, $rendered->head);
        $this->assertSame($response->body, $rendered->body);
    }
}
