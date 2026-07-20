<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouterExtensionTest;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\Pipeline\Pipeline as BasePipeline;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\RouteCollectionInterface;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Routing\RoutingTestCase;
use ReflectionProperty;
use RuntimeException;
use WeakMap;

class RouterExtensionTest extends RoutingTestCase
{
    public function testBothDispatchPathsUseThePipelineAndMiddlewareHooks(): void
    {
        $container = new Container;
        $middleware = new RouterTestMiddleware;
        $container->instance(RouterTestMiddleware::class, $middleware);
        $router = $this->router($container);
        $router->get('hooked', static fn (): string => 'route')
            ->middleware(RouterTestMiddleware::class);

        $response = $router->dispatch(Request::create('/hooked', 'GET'));
        $callbackResponse = $router->dispatchToCallback(
            Request::create('/hooked', 'GET'),
            static fn (): string => 'callback',
        );

        $this->assertSame('route', $response->getContent());
        $this->assertSame('callback', $callbackResponse->getContent());
        $this->assertSame(2, $router->pipelineCreations);
        $this->assertSame(2, $router->middlewareResolutions);
        $this->assertSame(2, $middleware->runs);
    }

    public function testMiddlewareOverrideCanRetainRequiredMiddlewareWhenUserMiddlewareIsDisabled(): void
    {
        $container = new Container;
        $container->instance('middleware.disable', true);
        $middleware = new RouterTestMiddleware;
        $container->instance(RouterTestMiddleware::class, $middleware);
        $router = $this->router($container);
        $router->prependRequiredMiddleware = true;
        $router->get('required', static fn (): string => 'response')
            ->middleware(RouterTestMiddleware::class);

        $response = $router->dispatch(Request::create('/required', 'GET'));

        $this->assertSame('response', $response->getContent());
        $this->assertSame(0, $middleware->runs);
        $this->assertSame(1, $router->requiredMiddlewareRuns);
        $this->assertSame(1, $router->pipelineCreations);
    }

    public function testGroupStackIsRestoredAfterThrowingClosuresAndRouteFiles(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'outer'], function (Router $router): void {
            try {
                $router->group(['prefix' => 'inner'], static function (): void {
                    throw new RuntimeException('route closure failed');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('route closure failed', $exception->getMessage());
            }

            $this->assertSame('outer', $router->getLastGroupPrefix());
            $router->get('after-closure', static fn (): string => 'ok');
        });

        $this->assertSame([], $router->getGroupStack());
        $this->assertSame('outer/after-closure', $router->getRoutes()->getRoutes()[0]->uri());

        $filesystem = new Filesystem;
        $tempDirectory = ParallelTesting::tempDir('RouterExtensionTest');
        $filesystem->ensureDirectoryExists($tempDirectory);
        $routeFile = $tempDirectory . '/throwing-routes.php';
        $filesystem->put($routeFile, "<?php\n\nthrow new RuntimeException('route file failed');\n");

        try {
            $router->group(['prefix' => 'leaked'], $routeFile);
            $this->fail('Expected the throwing route file to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('route file failed', $exception->getMessage());
        } finally {
            $filesystem->deleteDirectory($tempDirectory);
        }

        $this->assertSame([], $router->getGroupStack());
        $router->get('after-file', static fn (): string => 'ok');
        $this->assertSame('after-file', $router->getRoutes()->getRoutes()[1]->uri());
    }

    public function testCallableShapesRegisterWarmReflectAndDispatch(): void
    {
        $router = $this->router();
        $controller = new RouterTestController;
        $invokable = new RouterTestInvoker;
        $routes = [
            $router->get('closure/{value}', static fn (string $value): string => 'closure:' . $value)
                ->name('closure'),
            $router->get('array/{value}', [RouterTestController::class, 'show'])
                ->name('array'),
            $router->get('string/{value}', RouterTestController::class . '@show')
                ->name('string'),
            $router->get('invokable-class/{value}', RouterTestInvoker::class)
                ->name('invokable-class'),
            $router->get('invokable-object/{value}', $invokable)
                ->name('invokable-object'),
            $router->get('object-method/{value}', [$controller, 'show'])
                ->name('object-method'),
            $router->get('nested-object-method/{value}', ['uses' => [$controller, 'show']])
                ->name('nested-object-method'),
            $router->get('nested-class-method/{value}', ['uses' => [RouterTestController::class, 'show']])
                ->name('nested-class-method'),
        ];

        $this->assertFalse($router->referencesController($invokable));
        $router->compileAndWarm();

        foreach ($routes as $route) {
            $parameters = $route->signatureParameters();

            $this->assertCount(1, $parameters);
            $this->assertSame('value', $parameters[0]->getName());
        }

        foreach ([
            '/closure/one' => 'closure:one',
            '/array/two' => 'controller:two',
            '/string/three' => 'controller:three',
            '/invokable-class/four' => 'invokable:four',
            '/invokable-object/five' => 'invokable:five',
            '/object-method/six' => 'controller:six',
            '/nested-object-method/seven' => 'controller:seven',
            '/nested-class-method/eight' => 'controller:eight',
        ] as $uri => $expected) {
            $this->assertSame(
                $expected,
                $router->dispatch(Request::create($uri, 'GET'))->getContent(),
            );
        }
    }

    public function testIsolatedCompilationPreservesGlobalRoutesUrlGeneratorAndWarmedCaches(): void
    {
        $container = new Container;
        $applicationRouter = $this->router($container);
        $applicationAction = static fn (): string => 'application';
        $applicationRouter->get('application', $applicationAction)->name('application');
        $applicationRoutes = $applicationRouter->getRoutes();
        $applicationRoutes->refreshNameLookups();
        $container->instance('routes', $applicationRoutes);
        $url = new UrlGenerator(
            $applicationRoutes,
            Request::create('https://example.test'),
        );
        $container->instance('url', $url);
        $container->rebinding('routes', static function (
            Container $container,
            RouteCollectionInterface $routes,
        ) use ($url): void {
            $url->setRoutes($routes);
        });
        $applicationRouter->warmUp();

        $objectCache = (new ReflectionProperty(RouteSignatureParameters::class, 'objectCache'))->getValue();
        $this->assertInstanceOf(WeakMap::class, $objectCache);
        $this->assertTrue(isset($objectCache[$applicationAction]));

        $isolatedRouter = $this->router($container, isolated: true);
        $isolatedRouter->get('private', static fn (): string => 'private')->name('private');
        $isolatedRouter->compileAndWarm();

        $this->assertSame($applicationRoutes, $container->make('routes'));
        $this->assertSame('https://example.test/application', $url->route('application'));
        $objectCache = (new ReflectionProperty(RouteSignatureParameters::class, 'objectCache'))->getValue();
        $this->assertTrue(isset($objectCache[$applicationAction]));
    }

    public function testPrimaryRouteReplacementStillFlushesAndRebindsGlobalState(): void
    {
        $container = new Container;
        $router = $this->router($container);
        $action = static fn (): string => 'old';
        $router->get('old', $action);
        $router->warmUp();
        $this->assertTrue(isset(
            (new ReflectionProperty(RouteSignatureParameters::class, 'objectCache'))->getValue()[$action],
        ));
        $replacement = new RouteCollection;
        $replacement->add(new Route('GET', 'new', static fn (): string => 'new'));

        $router->setRoutes($replacement);

        $this->assertSame($replacement, $router->getRoutes());
        $this->assertSame($replacement, $container->make('routes'));
        $objectCache = (new ReflectionProperty(RouteSignatureParameters::class, 'objectCache'))->getValue();
        $this->assertInstanceOf(WeakMap::class, $objectCache);
        $this->assertCount(0, $objectCache);
    }

    private function router(
        ?Container $container = null,
        bool $isolated = false,
    ): TrackingRouter {
        $container ??= new Container;
        $router = $isolated
            ? new IsolatedTrackingRouter(new Dispatcher($container), $container)
            : new TrackingRouter(new Dispatcher($container), $container);
        $container->bind(
            CallableDispatcherContract::class,
            static fn (Container $container): CallableDispatcher => new CallableDispatcher($container),
        );
        $container->bind(
            ControllerDispatcherContract::class,
            static fn (Container $container): ControllerDispatcher => new ControllerDispatcher($container),
        );

        return $router;
    }
}

class TrackingRouter extends Router
{
    public int $pipelineCreations = 0;

    public int $middlewareResolutions = 0;

    public bool $prependRequiredMiddleware = false;

    public int $requiredMiddlewareRuns = 0;

    public function referencesController(mixed $action): bool
    {
        return $this->actionReferencesController($action);
    }

    protected function newPipeline(): BasePipeline
    {
        ++$this->pipelineCreations;

        return parent::newPipeline();
    }

    protected function middlewareFor(Route $route): array
    {
        ++$this->middlewareResolutions;
        $middleware = parent::middlewareFor($route);

        if ($this->prependRequiredMiddleware) {
            array_unshift(
                $middleware,
                function (Request $request, Closure $next): mixed {
                    ++$this->requiredMiddlewareRuns;

                    return $next($request);
                },
            );
        }

        return $middleware;
    }
}

class IsolatedTrackingRouter extends TrackingRouter
{
    protected function ownsGlobalRouteState(): bool
    {
        return false;
    }
}

class RouterTestController
{
    public function show(string $value): string
    {
        return 'controller:' . $value;
    }
}

class RouterTestInvoker
{
    public function __invoke(string $value): string
    {
        return 'invokable:' . $value;
    }
}

class RouterTestMiddleware
{
    public int $runs = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        ++$this->runs;

        return $next($request);
    }
}
