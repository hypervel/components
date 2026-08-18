<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use ArrayIterator;
use Hypervel\Http\Request;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Support\Arr;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RequestContext as SymfonyRequestContext;

class CompiledRouteCollectionTest extends RoutingTestCase
{
    protected RouteCollection $routeCollection;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = $this->app->make('router');

        $this->routeCollection = new RouteCollection;
    }

    protected function tearDown(): void
    {
        unset($this->routeCollection, $this->router);

        parent::tearDown();
    }

    protected function collection(): CompiledRouteCollection
    {
        return $this->routeCollection->toCompiledRouteCollection($this->router, $this->app);
    }

    public function testRouteCollectionCanAddRoute()
    {
        $this->routeCollection->add($this->newRoute('GET', 'foo', [
            'uses' => 'FooController@index',
            'as' => 'foo_index',
        ]));

        $this->assertCount(1, $this->collection());
    }

    public function testRouteCollectionAddReturnsTheRoute()
    {
        $outputRoute = $this->collection()->add($inputRoute = $this->newRoute('GET', 'foo', [
            'uses' => 'FooController@index',
            'as' => 'foo_index',
        ]));

        $this->assertInstanceOf(Route::class, $outputRoute);
        $this->assertEquals($inputRoute, $outputRoute);
    }

    public function testRouteCollectionCanRetrieveByName()
    {
        $this->routeCollection->add($routeIndex = $this->newRoute('GET', 'foo/index', [
            'uses' => 'FooController@index',
            'as' => 'route_name',
        ]));

        $routes = $this->collection();

        $this->assertSame('route_name', $routeIndex->getName());
        $this->assertSame('route_name', $routes->getByName('route_name')->getName());
        $this->assertEquals($routeIndex, $routes->getByName('route_name'));
    }

    public function testRouteCollectionCanRetrieveByAction()
    {
        $this->routeCollection->add($routeIndex = $this->newRoute('GET', 'foo/index', $action = [
            'uses' => 'FooController@index',
        ]));

        $route = $this->collection()->getByAction('FooController@index');

        $this->assertSame($action, Arr::except($routeIndex->getAction(), 'as'));
        $this->assertSame($action, Arr::except($route->getAction(), 'as'));
    }

    public function testCompiledAndNonCompiledUrlResolutionHasSamePrecedenceForActions(): void
    {
        $this->router->get('/foo/{bar}', ['FooController', 'show']);
        $this->router->get('/foo/{bar}/{baz}', ['FooController', 'show']);
        $this->router->getRoutes()->refreshActionLookups();

        $this->assertSame('foo/{bar}', $this->router->getRoutes()->getByAction('FooController@show')->uri);

        $this->router->setCompiledRoutes($this->router->getRoutes()->compile());
        $this->assertSame('foo/{bar}', $this->app->make('router')->getRoutes()->getByAction('FooController@show')->uri);
    }

    public function testCompiledAndNonCompiledUrlResolutionHasSamePrecedenceForNames()
    {
        $this->router->get('/foo/{bar}', ['FooController', 'show'])->name('foo.show');
        $this->router->get('/foo/{bar}/{baz}', ['FooController', 'show'])->name('foo.show');
        $this->router->getRoutes()->refreshNameLookups();

        $this->assertSame('foo/{bar}', $this->router->getRoutes()->getByName('foo.show')->uri);
    }

    public function testRouteCollectionCanGetIterator()
    {
        $this->routeCollection->add($this->newRoute('GET', 'foo/index', [
            'uses' => 'FooController@index',
            'as' => 'foo_index',
        ]));

        $this->assertInstanceOf(ArrayIterator::class, $this->collection()->getIterator());
    }

    public function testRouteCollectionCanGetIteratorWhenEmpty()
    {
        $routes = $this->collection();

        $this->assertCount(0, $routes);
        $this->assertInstanceOf(ArrayIterator::class, $routes->getIterator());
    }

    public function testRouteCollectionCanGetIteratorWhenRoutesAreAdded()
    {
        $this->routeCollection->add($routeIndex = $this->newRoute('GET', 'foo/index', [
            'uses' => 'FooController@index',
            'as' => 'foo_index',
        ]));

        $routes = $this->collection();

        $this->assertCount(1, $routes);

        $this->routeCollection->add($routeShow = $this->newRoute('GET', 'bar/show', [
            'uses' => 'BarController@show',
            'as' => 'bar_show',
        ]));

        $routes = $this->collection();

        $this->assertCount(2, $routes);

        $this->assertInstanceOf(ArrayIterator::class, $routes->getIterator());
    }

    public function testRouteCollectionCanHandleSameRoute()
    {
        $this->routeCollection->add($routeIndex = $this->newRoute('GET', 'foo/index', [
            'uses' => 'FooController@index',
            'as' => 'foo_index',
        ]));

        $routes = $this->collection();

        $this->assertCount(1, $routes);

        // Add exactly the same route
        $this->routeCollection->add($routeIndex);

        $routes = $this->collection();

        $this->assertCount(1, $routes);

        // Add a non-existing route
        $this->routeCollection->add($this->newRoute('GET', 'bar/show', [
            'uses' => 'BarController@show',
            'as' => 'bar_show',
        ]));

        $routes = $this->collection();

        $this->assertCount(2, $routes);
    }

    public function testRouteCollectionCanGetAllRoutes()
    {
        $this->routeCollection->add($routeIndex = $this->newRoute('GET', 'foo/index', [
            'uses' => 'FooController@index',
            'as' => 'foo_index',
        ]));
        $this->routeCollection->add($routeShow = $this->newRoute('GET', 'foo/show', [
            'uses' => 'FooController@show',
            'as' => 'foo_show',
        ]));
        $this->routeCollection->add($routeNew = $this->newRoute('POST', 'bar', [
            'uses' => 'BarController@create',
            'as' => 'bar_create',
        ]));

        $allRoutes = [
            $routeIndex,
            $routeShow,
            $routeNew,
        ];
        $this->assertEquals($allRoutes, $this->collection()->getRoutes());
    }

    public function testRouteCollectionCanGetRoutesByName()
    {
        $routesByName = [
            'foo_index' => $this->newRoute('GET', 'foo/index', [
                'uses' => 'FooController@index',
                'as' => 'foo_index',
            ]),
            'foo_show' => $this->newRoute('GET', 'foo/show', [
                'uses' => 'FooController@show',
                'as' => 'foo_show',
            ]),
            'bar_create' => $this->newRoute('POST', 'bar', [
                'uses' => 'BarController@create',
                'as' => 'bar_create',
            ]),
        ];

        $this->routeCollection->add($routesByName['foo_index']);
        $this->routeCollection->add($routesByName['foo_show']);
        $this->routeCollection->add($routesByName['bar_create']);

        $this->assertEquals($routesByName, $this->collection()->getRoutesByName());
    }

    public function testRouteCollectionCanGetRoutesByMethod()
    {
        $routes = [
            'foo_index' => $this->newRoute('GET', 'foo/index', [
                'uses' => 'FooController@index',
                'as' => 'foo_index',
            ]),
            'foo_show' => $this->newRoute('GET', 'foo/show', [
                'uses' => 'FooController@show',
                'as' => 'foo_show',
            ]),
            'bar_create' => $this->newRoute('POST', 'bar', [
                'uses' => 'BarController@create',
                'as' => 'bar_create',
            ]),
        ];

        $this->routeCollection->add($routes['foo_index']);
        $this->routeCollection->add($routes['foo_show']);
        $this->routeCollection->add($routes['bar_create']);

        $this->assertEquals([
            'GET' => [
                'foo/index' => $routes['foo_index'],
                'foo/show' => $routes['foo_show'],
            ],
            'HEAD' => [
                'foo/index' => $routes['foo_index'],
                'foo/show' => $routes['foo_show'],
            ],
            'POST' => [
                'bar' => $routes['bar_create'],
            ],
        ], $this->collection()->getRoutesByMethod());
    }

    public function testRouteCollectionCleansUpOverwrittenRoutes()
    {
        // Create two routes with the same path and method.
        $routeA = $this->newRoute('GET', 'product', ['controller' => 'View@view', 'as' => 'routeA']);
        $routeB = $this->newRoute('GET', 'product', ['controller' => 'OverwrittenView@view', 'as' => 'overwrittenRouteA']);

        $this->routeCollection->add($routeA);
        $this->routeCollection->add($routeB);

        // Check if the lookups of $routeA and $routeB are there.
        $this->assertEquals($routeA, $this->routeCollection->getByName('routeA'));
        $this->assertEquals($routeA, $this->routeCollection->getByAction('View@view'));
        $this->assertEquals($routeB, $this->routeCollection->getByName('overwrittenRouteA'));
        $this->assertEquals($routeB, $this->routeCollection->getByAction('OverwrittenView@view'));

        $routes = $this->collection();

        // The lookups of $routeA should not be there anymore, because they are no longer valid.
        $this->assertNull($routes->getByName('routeA'));
        $this->assertNull($routes->getByAction('View@view'));
        // The lookups of $routeB are still there.
        $this->assertEquals($routeB, $routes->getByName('overwrittenRouteA'));
        $this->assertEquals($routeB, $routes->getByAction('OverwrittenView@view'));
    }

    public function testMatchingThrowsNotFoundExceptionWhenRouteIsNotFound()
    {
        $this->routeCollection->add($this->newRoute('GET', '/', ['uses' => 'FooController@index']));

        $this->expectException(NotFoundHttpException::class);

        $this->collection()->match(Request::create('/foo'));
    }

    public function testMatchingThrowsMethodNotAllowedHttpExceptionWhenMethodIsNotAllowed()
    {
        $this->routeCollection->add($this->newRoute('GET', '/foo', ['uses' => 'FooController@index']));

        try {
            $this->collection()->match(Request::create('/foo', 'POST'));
            $this->fail('Expected the compiled route to reject the POST method.');
        } catch (MethodNotAllowedHttpException $exception) {
            $this->assertSame('GET, HEAD', $exception->getHeaders()['Allow']);
        }
    }

    public function testOptionsRequestUsesCompiledAllowedMethods(): void
    {
        $this->routeCollection->add($this->newRoute('GET', '/foo', ['uses' => 'FooController@index']));

        $route = $this->collection()->match(Request::create('/foo', 'OPTIONS'));

        $this->assertSame(['OPTIONS'], $route->methods());
    }

    public function testMatchingThrowsExceptionWhenMethodIsNotAllowedWhileSameRouteIsAddedDynamically()
    {
        $this->routeCollection->add($this->newRoute('GET', '/', ['uses' => 'FooController@index']));

        $routes = $this->collection();

        $routes->add($this->newRoute('POST', '/', ['uses' => 'FooController@index']));

        $this->expectException(MethodNotAllowedHttpException::class);

        $routes->match(Request::create('/', 'PUT'));
    }

    public function testMatchingRouteWithSameDynamicallyAddedRouteAlwaysMatchesCachedOneFirst()
    {
        $this->routeCollection->add(
            $route = $this->newRoute('GET', '/', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $routes = $this->collection();

        $routes->add($this->newRoute('GET', '/', ['uses' => 'FooController@index', 'as' => 'bar']));

        $this->assertSame('foo', $routes->match(Request::create('/', 'GET'))->getName());
    }

    public function testMatchingFindsRouteWithDifferentMethodDynamically()
    {
        $this->routeCollection->add($this->newRoute('GET', '/foo', ['uses' => 'FooController@index']));

        $routes = $this->collection();

        $routes->add($route = $this->newRoute('POST', '/foo', ['uses' => 'FooController@index']));

        $this->assertSame($route, $routes->match(Request::create('/foo', 'POST')));
    }

    public function testMatchingWildcardFromCompiledRoutesAlwaysTakesPrecedent()
    {
        $this->routeCollection->add(
            $route = $this->newRoute('GET', '{wildcard}', ['uses' => 'FooController@index', 'as' => 'foo'])
                ->where('wildcard', '.*')
        );

        $routes = $this->collection();

        $routes->add(
            $this->newRoute('GET', '{wildcard}', ['uses' => 'FooController@index', 'as' => 'bar'])
                ->where('wildcard', '.*')
        );

        $this->assertSame('foo', $routes->match(Request::create('/foo', 'GET'))->getName());
    }

    public function testCompiledMatchBindsPathParametersAndOriginalValues(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/users/{user}/posts/{post}', [
                'uses' => 'FooController@index',
                'as' => 'posts.show',
            ])
        );

        $route = $this->collection()->match(Request::create('/users/12/posts/34', 'GET'));

        $this->assertSame(['user' => '12', 'post' => '34'], $route->parameters());
        $this->assertSame(['user' => '12', 'post' => '34'], $route->originalParameters());

        $route->setParameter('user', 'changed');

        $this->assertSame(['user' => 'changed', 'post' => '34'], $route->parameters());
        $this->assertSame(['user' => '12', 'post' => '34'], $route->originalParameters());
    }

    public function testCompiledMatchBindsEmptyParameterState(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/status', [
                'uses' => 'FooController@index',
                'as' => 'status',
            ])
        );

        $route = $this->collection()->match(Request::create('/status', 'GET'));

        $this->assertTrue($route->hasParameters());
        $this->assertSame([], $route->parameters());
        $this->assertSame([], $route->originalParameters());

        $route->setParameter('runtime', 'value');

        $this->assertSame(['runtime' => 'value'], $route->parameters());
        $this->assertSame([], $route->originalParameters());

        $route->forgetParameter('runtime');

        $this->assertSame([], $route->parameters());
    }

    public function testUnconstrainedStaticMatchPreservesHostValidation(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/status', [
                'uses' => 'FooController@index',
                'as' => 'status',
            ])
        );

        $request = StaticMatchTrackingRequest::create('/status', 'GET');
        $route = $this->collection()->match($request);

        $this->assertSame('status', $route->getName());
        $this->assertSame(1, $request->hostReads);
    }

    public function testUnconstrainedStaticMatchRejectsAnInvalidHost(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/status', [
                'uses' => 'FooController@index',
                'as' => 'status',
            ])
        );
        $request = Request::create('/status', 'GET');
        $request->headers->set('HOST', 'invalid host');

        $this->expectException(SuspiciousOperationException::class);

        $this->collection()->match($request);
    }

    public function testStaticCompiledFallbackYieldsToDynamicNonFallbackRoute(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/status', [
                'uses' => 'FooController@index',
                'as' => 'compiled-fallback',
            ])->fallback()
        );
        $routes = $this->collection();
        $routes->add(
            $this->newRoute('GET', '/status', [
                'uses' => 'FooController@index',
                'as' => 'dynamic',
            ])
        );

        $this->assertSame(
            'dynamic',
            $routes->match(Request::create('/status', 'GET'))->getName()
        );
    }

    public function testCompiledMatchUsesAnOverriddenRouteBindMethod(): void
    {
        $router = new BindTrackingRouter($this->app->make('events'), $this->app);
        $routes = new RouteCollection;
        $routes->add($router->newRoute('GET', '/users/{user}', [
            'uses' => 'FooController@index',
            'as' => 'users.show',
        ]));

        $route = $routes
            ->toCompiledRouteCollection($router, $this->app)
            ->match(Request::create('/users/42', 'GET'));

        $this->assertInstanceOf(BindTrackingRoute::class, $route);
        $this->assertSame(1, $route->bindCalls);
        $this->assertSame(['user' => '42'], $route->parameters());
    }

    public function testCompiledMatchBindsDomainAndPathParameters(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/users/{user}', [
                'uses' => 'FooController@index',
                'as' => 'tenant.users.show',
                'domain' => '{tenant}.example.com',
            ])
        );

        $route = $this->collection()->match(
            Request::create('https://hypervel.example.com/users/42', 'GET')
        );

        $this->assertSame(['tenant' => 'hypervel', 'user' => '42'], $route->parameters());
        $this->assertSame(['tenant' => 'hypervel', 'user' => '42'], $route->originalParameters());
    }

    public function testCompiledMatchHonorsHttpsOnlyRoute(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/secure/{resource}', [
                'uses' => 'FooController@index',
                'as' => 'secure',
                'https',
            ])
        );

        $this->assertSame(
            'secure',
            $this->collection()->match(Request::create('https://example.com/secure/report'))->getName()
        );

        $this->expectException(NotFoundHttpException::class);

        $this->collection()->match(Request::create('http://example.com/secure/report'));
    }

    public function testCompiledMatchHonorsHttpOnlyRoute(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/insecure', [
                'uses' => 'FooController@index',
                'as' => 'insecure',
                'http',
            ])
        );

        $this->assertSame(
            'insecure',
            $this->collection()->match(Request::create('http://example.com/insecure'))->getName()
        );

        $this->expectException(NotFoundHttpException::class);

        $this->collection()->match(Request::create('https://example.com/insecure'));
    }

    public function testCompiledConditionReceivesCompleteRequestContext(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/conditional', [
                'uses' => 'FooController@index',
                'as' => 'conditional',
            ])
        );

        $compiled = $this->routeCollection->compile();
        $compiled['compiled'][1]['/conditional'][0][6] = -1;
        $compiled['compiled'][4] = static fn (
            int $condition,
            SymfonyRequestContext $context
        ): bool => $condition === -1 && $context->getQueryString() === 'token=1';
        $collection = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
            ->setRouter($this->router)
            ->setContainer($this->app);

        $this->assertSame(
            'conditional',
            $collection->match(Request::create('/conditional?token=1'))->getName()
        );

        $this->expectException(NotFoundHttpException::class);

        $collection->match(Request::create('/conditional?token=2'));
    }

    public function testCompiledMatchAppliesOptionalParameterDefaults(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/reports/{period?}', [
                'uses' => 'FooController@index',
                'as' => 'reports.show',
            ])->defaults('period', 'current')
        );

        $route = $this->collection()->match(Request::create('/reports', 'GET'));

        $this->assertSame(['period' => 'current'], $route->parameters());
        $this->assertSame(['period' => 'current'], $route->originalParameters());
    }

    public function testCompiledMatchKeepsUriParameterOrderWhenDefaultsArePresent(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/reports/{category}/{period?}', [
                'uses' => 'FooController@index',
                'as' => 'reports.category',
            ])->defaults('period', 'current')
        );

        $route = $this->collection()->match(Request::create('/reports/sales', 'GET'));

        $this->assertSame(
            ['category' => 'sales', 'period' => 'current'],
            $route->parameters()
        );
    }

    public function testCompiledMatchAppliesDefaultsWithoutUriParameters(): void
    {
        $this->routeCollection->add(
            $this->newRoute('GET', '/reports', [
                'uses' => 'FooController@index',
                'as' => 'reports.index',
            ])->defaults('format', 'summary')
        );

        $route = $this->collection()->match(Request::create('/reports', 'GET'));

        $this->assertSame(['format' => 'summary'], $route->parameters());
        $this->assertSame(['format' => 'summary'], $route->originalParameters());
    }

    public function testMatchingDynamicallyAddedRoutesTakePrecedenceOverFallbackRoutes()
    {
        $this->routeCollection->add($this->fallbackRoute(['uses' => 'FooController@index']));
        $this->routeCollection->add(
            $this->newRoute('GET', '/foo/{id}', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $routes = $this->collection();

        $routes->add($this->newRoute('GET', '/bar/{id}', ['uses' => 'FooController@index', 'as' => 'bar']));

        $this->assertSame('bar', $routes->match(Request::create('/bar/1', 'GET'))->getName());
    }

    public function testMatchingFallbackRouteCatchesAll()
    {
        $this->routeCollection->add($this->fallbackRoute(['uses' => 'FooController@index', 'as' => 'fallback']));
        $this->routeCollection->add(
            $this->newRoute('GET', '/foo/{id}', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $routes = $this->collection();

        $routes->add($this->newRoute('GET', '/bar/{id}', ['uses' => 'FooController@index', 'as' => 'bar']));

        $route = $routes->match(Request::create('/baz/1', 'GET'));

        $this->assertSame('fallback', $route->getName());
        $this->assertSame(['fallbackPlaceholder' => 'baz/1'], $route->parameters());
    }

    public function testMatchingCachedFallbackTakesPrecedenceOverDynamicFallback()
    {
        $this->routeCollection->add($this->fallbackRoute(['uses' => 'FooController@index', 'as' => 'fallback']));

        $routes = $this->collection();

        $routes->add($this->fallbackRoute(['uses' => 'FooController@index', 'as' => 'dynamic_fallback']));

        $this->assertSame('fallback', $routes->match(Request::create('/baz/1', 'GET'))->getName());
    }

    public function testMatchingCachedFallbackTakesPrecedenceOverDynamicRouteWithWrongMethod()
    {
        $this->routeCollection->add($this->fallbackRoute(['uses' => 'FooController@index', 'as' => 'fallback']));

        $routes = $this->collection();

        $routes->add($this->newRoute('POST', '/bar/{id}', ['uses' => 'FooController@index', 'as' => 'bar']));

        $this->assertSame('fallback', $routes->match(Request::create('/bar/1', 'GET'))->getName());
    }

    public function testSlashPrefixIsProperlyHandled()
    {
        $this->routeCollection->add($this->newRoute('GET', 'foo/bar', ['uses' => 'FooController@index', 'prefix' => '/']));

        $route = $this->collection()->getByAction('FooController@index');

        $this->assertSame('foo/bar', $route->uri());
    }

    public function testRouteWithoutNamespaceIsFound()
    {
        $this->routeCollection->add($this->newRoute('GET', 'foo/bar', ['controller' => '\App\FooController']));

        $route = $this->collection()->getByAction('App\FooController');

        $this->assertSame('foo/bar', $route->uri());
    }

    public function testGroupPrefixAndRoutePrefixAreProperlyHandled()
    {
        $this->routeCollection->add($this->newRoute('GET', 'foo/bar', ['uses' => 'FooController@index', 'prefix' => '{locale}'])->prefix('pre'));

        $route = $this->collection()->getByAction('FooController@index');

        $this->assertSame('pre/{locale}', $route->getPrefix());
    }

    public function testGroupGenerateNameForDuplicateRouteNamesThatEndWithDot()
    {
        $this->routeCollection->add($this->newRoute('GET', 'foo', ['uses' => 'FooController@index'])->name('foo.'));
        $this->routeCollection->add($route = $this->newRoute('GET', 'bar', ['uses' => 'BarController@index'])->name('foo.'));

        $routes = $this->collection();

        $this->assertSame('BarController@index', $routes->match(Request::create('/bar', 'GET'))->getAction()['uses']);
    }

    public function testRouteBindingsAreProperlySaved()
    {
        $this->routeCollection->add($this->newRoute('GET', 'posts/{post:slug}/show', [
            'uses' => 'FooController@index',
            'prefix' => 'profile/{user:username}',
            'as' => 'foo',
        ]));

        $route = $this->collection()->getByName('foo');

        $this->assertSame('profile/{user}/posts/{post}/show', $route->uri());
        $this->assertSame(['user' => 'username', 'post' => 'slug'], $route->bindingFields());
    }

    public function testMatchingSlashedRoutes()
    {
        $this->routeCollection->add(
            $route = $this->newRoute('GET', 'foo/bar', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $this->assertSame('foo', $this->collection()->match(Request::create('/foo/bar/'))->getName());
    }

    public function testMatchingUriWithQuery()
    {
        $this->routeCollection->add(
            $route = $this->newRoute('GET', 'foo/bar', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $this->assertSame('foo', $this->collection()->match(Request::create('/foo/bar/?foo=bar'))->getName());
    }

    public function testMatchingRootUri()
    {
        $this->routeCollection->add(
            $route = $this->newRoute('GET', '/', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $this->assertSame('foo', $this->collection()->match(Request::create('http://example.com'))->getName());
    }

    public function testTrailingSlashIsTrimmedWhenMatchingCachedRoutes()
    {
        $this->routeCollection->add(
            $this->newRoute('GET', 'foo/bar', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $request = Request::create('/foo/bar/');

        // Access to request path info before matching route
        $request->getPathInfo();

        $this->assertSame('foo', $this->collection()->match($request)->getName());
    }

    public function testTrailingSlashWithQueryStringIsTrimmedWhenMatchingCachedRoutes()
    {
        $this->routeCollection->add(
            $this->newRoute('GET', 'foo/bar', ['uses' => 'FooController@index', 'as' => 'foo'])
        );

        $request = Request::create('/foo/bar/?foo=bar');

        // Access to request path info before matching route
        $request->getPathInfo();

        $this->assertSame('foo', $this->collection()->match($request)->getName());
    }

    public function testRouteWithSamePathAndSameMethodButDiffDomainNameWithOptionsMethod()
    {
        $routes = [
            'foo_domain' => $this->newRoute('GET', 'same/path', [
                'uses' => 'FooController@index',
                'as' => 'foo',
                'domain' => 'foo.localhost',
            ]),
            'bar_domain' => $this->newRoute('GET', 'same/path', [
                'uses' => 'BarController@index',
                'as' => 'bar',
                'domain' => 'bar.localhost',
            ]),
            'no_domain' => $this->newRoute('GET', 'same/path', [
                'uses' => 'BarController@index',
                'as' => 'no_domain',
            ]),
        ];

        $this->routeCollection->add($routes['foo_domain']);
        $this->routeCollection->add($routes['bar_domain']);
        $this->routeCollection->add($routes['no_domain']);

        $expectedMethods = [
            'OPTIONS',
        ];

        $this->assertSame($expectedMethods, $this->collection()->match(
            Request::create('http://foo.localhost/same/path', 'OPTIONS')
        )->methods);

        $this->assertSame($expectedMethods, $this->collection()->match(
            Request::create('http://bar.localhost/same/path', 'OPTIONS')
        )->methods);

        $this->assertSame($expectedMethods, $this->collection()->match(
            Request::create('http://no.localhost/same/path', 'OPTIONS')
        )->methods);

        $this->assertEquals([
            'HEAD' => [
                'foo.localhostsame/path' => $routes['foo_domain'],
                'bar.localhostsame/path' => $routes['bar_domain'],
                'same/path' => $routes['no_domain'],
            ],
            'GET' => [
                'foo.localhostsame/path' => $routes['foo_domain'],
                'bar.localhostsame/path' => $routes['bar_domain'],
                'same/path' => $routes['no_domain'],
            ],
        ], $this->collection()->getRoutesByMethod());
    }

    /**
     * Create a new Route object.
     */
    protected function newRoute(array|string $methods, string $uri, mixed $action): Route
    {
        return (new Route($methods, $uri, $action))
            ->setRouter($this->router)
            ->setContainer($this->app);
    }

    /**
     * Create a new fallback Route object.
     */
    protected function fallbackRoute(mixed $action): Route
    {
        $placeholder = 'fallbackPlaceholder';

        return $this->newRoute(
            'GET',
            "{{$placeholder}}",
            $action
        )->where($placeholder, '.*')->fallback();
    }
}

class BindTrackingRouter extends Router
{
    public function newRoute(array|string $methods, string $uri, mixed $action): Route
    {
        return (new BindTrackingRoute($methods, $uri, $action))
            ->setRouter($this)
            ->setContainer($this->container);
    }
}

class BindTrackingRoute extends Route
{
    public int $bindCalls = 0;

    public function bind(Request $request): static
    {
        ++$this->bindCalls;

        return parent::bind($request);
    }
}

class StaticMatchTrackingRequest extends Request
{
    public int $hostReads = 0;

    public function getHost(): string
    {
        ++$this->hostReads;

        return parent::getHost();
    }
}
