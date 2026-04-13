<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Http\Response;
use Hypervel\Inertia\AlwaysProp;
use Hypervel\Inertia\ComponentNotFoundException;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\MergeProp;
use Hypervel\Inertia\OnceProp;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Inertia\ResponseFactory;
use Hypervel\Inertia\ScrollMetadata;
use Hypervel\Inertia\ScrollProp;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Session\NullSessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\Facades\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\Enums\IntBackedEnum;
use Hypervel\Tests\Inertia\Fixtures\Enums\StringBackedEnum;
use Hypervel\Tests\Inertia\Fixtures\Enums\UnitEnum;
use Hypervel\Tests\Inertia\Fixtures\ExampleInertiaPropsProvider;
use Hypervel\Tests\Inertia\Fixtures\ExampleMiddleware;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class ResponseFactoryTest extends TestCase
{
    public function testCanMacro(): void
    {
        $factory = new ResponseFactory;
        $factory->macro('foo', function () {
            return 'bar';
        });

        /* @phpstan-ignore-next-line */
        $this->assertEquals('bar', $factory->foo());
    }

    public function testLocationResponseForInertiaRequests(): void
    {
        Request::macro('inertia', function () {
            return true;
        });

        $response = (new ResponseFactory)->location('https://inertiajs.com');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertEquals('https://inertiajs.com', $response->headers->get('X-Inertia-Location'));
    }

    public function testLocationResponseForNonInertiaRequests(): void
    {
        Request::macro('inertia', function () {
            return false;
        });

        $response = (new ResponseFactory)->location('https://inertiajs.com');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals('https://inertiajs.com', $response->headers->get('location'));
    }

    public function testLocationResponseForInertiaRequestsUsingRedirectResponse(): void
    {
        Request::macro('inertia', function () {
            return true;
        });

        $redirect = new RedirectResponse('https://inertiajs.com');
        $response = (new ResponseFactory)->location($redirect);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(409, $response->getStatusCode());
        $this->assertEquals('https://inertiajs.com', $response->headers->get('X-Inertia-Location'));
    }

    public function testLocationResponseForNonInertiaRequestsUsingRedirectResponse(): void
    {
        $redirect = new RedirectResponse('https://inertiajs.com');
        $response = (new ResponseFactory)->location($redirect);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals('https://inertiajs.com', $response->headers->get('location'));
    }

    public function testLocationRedirectsAreNotModified(): void
    {
        $response = (new ResponseFactory)->location('/foo');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals('/foo', $response->headers->get('location'));
    }

    public function testLocationResponseForNonInertiaRequestsUsingRedirectResponseWithExistingSessionAndRequestProperties(): void
    {
        $redirect = new RedirectResponse('https://inertiajs.com');
        $redirect->setSession($session = new Store('test', new NullSessionHandler));
        $redirect->setRequest($request = new HttpRequest);
        $response = (new ResponseFactory)->location($redirect);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals('https://inertiajs.com', $response->headers->get('location'));
        $this->assertSame($session, $response->getSession());
        $this->assertSame($request, $response->getRequest());
        $this->assertSame($response, $redirect);
    }

    public function testTheVersionCanBeAClosure(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $this->assertSame('', Inertia::getVersion());

            Inertia::version(function () {
                return hash('xxh128', 'Inertia');
            });

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'f445bd0a2c393a5af14fc677f59980a9',
        ]);

        $response->assertSuccessful();
        $response->assertJson(['component' => 'User/Edit']);
    }

    public function testTheUrlCanBeResolvedWithACustomResolver(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::resolveUrlUsing(function ($request, ResponseFactory $otherDependency) {
                $this->assertInstanceOf(HttpRequest::class, $request);
                $this->assertInstanceOf(ResponseFactory::class, $otherDependency);

                return '/my-custom-url';
            });

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'url' => '/my-custom-url',
        ]);
    }

    public function testSharedDataCanBeSharedFromAnywhere(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('foo', 'bar');

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'foo' => 'bar',
            ],
        ]);
    }

    public function testDotPropsAreMergedFromShared(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('auth.user', [
                'name' => 'Jonathan',
            ]);

            return Inertia::render('User/Edit', [
                'auth.user.can.create_group' => false,
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'auth' => [
                    'user' => [
                        'name' => 'Jonathan',
                        'can' => [
                            'create_group' => false,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testSharedDataCanResolveClosureArguments(): void
    {
        Inertia::share('query', fn (HttpRequest $request) => $request->query());

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/?foo=bar', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'query' => [
                    'foo' => 'bar',
                ],
            ],
        ]);
    }

    public function testDotPropsWithCallbacksAreMergedFromShared(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('auth.user', fn () => [
                'name' => 'Jonathan',
            ]);

            return Inertia::render('User/Edit', [
                'auth.user.can.create_group' => false,
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'auth' => [
                    'user' => [
                        'name' => 'Jonathan',
                        'can' => [
                            'create_group' => false,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testCanFlushSharedData(): void
    {
        Inertia::share('foo', 'bar');
        $this->assertSame(['foo' => 'bar'], Inertia::getShared());
        Inertia::flushShared();
        $this->assertSame([], Inertia::getShared());
    }

    public function testCanCreateDeferredProp(): void
    {
        $factory = new ResponseFactory;
        $deferredProp = $factory->defer(function () {
            return 'A deferred value';
        });

        $this->assertInstanceOf(DeferProp::class, $deferredProp);
        $this->assertSame($deferredProp->group(), 'default');
    }

    public function testCanCreateDeferredPropWithCustomGroup(): void
    {
        $factory = new ResponseFactory;
        $deferredProp = $factory->defer(function () {
            return 'A deferred value';
        }, 'foo');

        $this->assertInstanceOf(DeferProp::class, $deferredProp);
        $this->assertSame($deferredProp->group(), 'foo');
    }

    public function testCanCreateMergedProp(): void
    {
        $factory = new ResponseFactory;
        $mergedProp = $factory->merge(function () {
            return 'A merged value';
        });

        $this->assertInstanceOf(MergeProp::class, $mergedProp);
    }

    public function testCanCreateDeepMergedProp(): void
    {
        $factory = new ResponseFactory;
        $mergedProp = $factory->deepMerge(function () {
            return 'A merged value';
        });

        $this->assertInstanceOf(MergeProp::class, $mergedProp);
    }

    public function testCanCreateDeferredAndMergedProp(): void
    {
        $factory = new ResponseFactory;
        $deferredProp = $factory->defer(function () {
            return 'A deferred + merged value';
        })->merge();

        $this->assertInstanceOf(DeferProp::class, $deferredProp);
    }

    public function testCanCreateDeferredAndDeepMergedProp(): void
    {
        $factory = new ResponseFactory;
        $deferredProp = $factory->defer(function () {
            return 'A deferred + merged value';
        })->deepMerge();

        $this->assertInstanceOf(DeferProp::class, $deferredProp);
    }

    public function testCanCreateOptionalProp(): void
    {
        $factory = new ResponseFactory;
        $optionalProp = $factory->optional(function () {
            return 'An optional value';
        });

        $this->assertInstanceOf(OptionalProp::class, $optionalProp);
    }

    public function testCanCreateScrollProp(): void
    {
        $factory = new ResponseFactory;
        $data = ['item1', 'item2'];

        $scrollProp = $factory->scroll($data);

        $this->assertInstanceOf(ScrollProp::class, $scrollProp);
        $this->assertSame($data, $scrollProp());
    }

    public function testCanCreateScrollPropWithMetadataProvider(): void
    {
        $factory = new ResponseFactory;
        $data = ['item1', 'item2'];
        $metadataProvider = new ScrollMetadata('custom', 1, 3, 2);

        $scrollProp = $factory->scroll($data, 'data', $metadataProvider);

        $this->assertInstanceOf(ScrollProp::class, $scrollProp);
        $this->assertSame($data, $scrollProp());
        $this->assertEquals([
            'pageName' => 'custom',
            'previousPage' => 1,
            'nextPage' => 3,
            'currentPage' => 2,
        ], $scrollProp->metadata());
    }

    public function testCanCreateOnceProp(): void
    {
        $factory = new ResponseFactory;
        $onceProp = $factory->once(function () {
            return 'A once value';
        });

        $this->assertInstanceOf(OnceProp::class, $onceProp);
    }

    public function testCanCreateDeferredAndOnceProp(): void
    {
        $factory = new ResponseFactory;
        $deferredProp = $factory->defer(function () {
            return 'A deferred + once value';
        })->once();

        $this->assertInstanceOf(DeferProp::class, $deferredProp);
        $this->assertTrue($deferredProp->shouldResolveOnce());
    }

    public function testCanCreateAlwaysProp(): void
    {
        $factory = new ResponseFactory;
        $alwaysProp = $factory->always(function () {
            return 'An always value';
        });

        $this->assertInstanceOf(AlwaysProp::class, $alwaysProp);
    }

    public function testWillAcceptArrayabeProps(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('foo', 'bar');

            return Inertia::render('User/Edit', new class implements Arrayable {
                public function toArray(): array
                {
                    return [
                        'foo' => 'bar',
                    ];
                }
            });
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);
        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'foo' => 'bar',
            ],
        ]);
    }

    public function testWillAcceptInstancesOfProvidesInertiaProps(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit', new ExampleInertiaPropsProvider([
                'foo' => 'bar',
            ]));
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);
        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'foo' => 'bar',
            ],
        ]);
    }

    public function testWillAcceptArraysContainingProvidesInertiaPropsInRender(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit', [
                'regular' => 'prop',
                new ExampleInertiaPropsProvider([
                    'from_object' => 'value',
                ]),
                'another' => 'normal_prop',
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);
        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'regular' => 'prop',
                'from_object' => 'value',
                'another' => 'normal_prop',
            ],
        ]);
    }

    public function testCanShareInstancesOfProvidesInertiaProps(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share(new ExampleInertiaPropsProvider([
                'shared' => 'data',
            ]));

            return Inertia::render('User/Edit', [
                'regular' => 'prop',
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);
        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'shared' => 'data',
                'regular' => 'prop',
            ],
        ]);
    }

    public function testCanShareArraysContainingProvidesInertiaProps(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share([
                'regular' => 'shared_prop',
                new ExampleInertiaPropsProvider([
                    'from_object' => 'shared_value',
                ]),
            ]);

            return Inertia::render('User/Edit', [
                'component' => 'prop',
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);
        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'regular' => 'shared_prop',
                'from_object' => 'shared_value',
                'component' => 'prop',
            ],
        ]);
    }

    public function testWillThrowExceptionIfComponentDoesNotExistWhenEnsuringIsEnabled(): void
    {
        config()->set('inertia.pages.ensure_pages_exist', true);

        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Inertia page component [foo] not found.');

        (new ResponseFactory)->render('foo');
    }

    public function testWillNotThrowExceptionIfComponentDoesNotExistWhenEnsuringIsDisabled(): void
    {
        config()->set('inertia.pages.ensure_pages_exist', false);

        $response = (new ResponseFactory)->render('foo');
        $this->assertInstanceOf(\Hypervel\Inertia\Response::class, $response);
    }

    public function testCanResolveComponentNameBeforeRendering(): void
    {
        $calledWith = null;

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () use (&$calledWith) {
            Inertia::transformComponentUsing(static function (string $name) use (&$calledWith): string {
                $calledWith = $name;

                return "{$name}/Page";
            });

            return Inertia::render('Fixtures/Example');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'Fixtures/Example/Page',
        ]);
        $this->assertSame('Fixtures/Example', $calledWith);
    }

    public function testResolvedComponentNameIsUsedForPageExistenceChecks(): void
    {
        $calledWith = null;

        config()->set('inertia.pages.ensure_pages_exist', true);
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () use (&$calledWith) {
            Inertia::transformComponentUsing(static function (string $name) use (&$calledWith): string {
                $calledWith = $name;

                return "{$name}/Page";
            });

            return Inertia::render('Fixtures/Example');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $this->assertSame('Fixtures/Example', $calledWith);
    }

    public function testRenderAcceptsBackedEnum(): void
    {
        $response = (new ResponseFactory)->render(StringBackedEnum::UsersIndex);
        $this->assertInstanceOf(\Hypervel\Inertia\Response::class, $response);

        /** @phpstan-ignore-next-line */
        $getComponent = fn () => $this->component;
        $this->assertSame('UsersPage/Index', $getComponent->call($response));
    }

    public function testRenderAcceptsUnitEnum(): void
    {
        $response = (new ResponseFactory)->render(UnitEnum::Index);
        $this->assertInstanceOf(\Hypervel\Inertia\Response::class, $response);

        /** @phpstan-ignore-next-line */
        $getComponent = fn () => $this->component;
        $this->assertSame('Index', $getComponent->call($response));
    }

    public function testRenderThrowsForNonStringBackedEnum(): void
    {
        $factory = new ResponseFactory;
        $this->expectException(InvalidArgumentException::class);
        $factory->render(IntBackedEnum::Zero);
    }

    public function testShareOnceSharesAOnceProp(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::shareOnce('settings', fn () => ['theme' => 'dark']);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'settings' => ['theme' => 'dark'],
            ],
            'onceProps' => [
                'settings' => [
                    'prop' => 'settings',
                    'expiresAt' => null,
                ],
            ],
        ]);
    }

    public function testShareOnceIsChainable(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $prop = Inertia::shareOnce('settings', fn () => ['theme' => 'dark'])
                ->as('app-settings')
                ->until(60);

            $this->assertInstanceOf(OnceProp::class, $prop);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $data = $response->json();

        $this->assertArrayHasKey('onceProps', $data);
        $this->assertArrayHasKey('app-settings', $data['onceProps']);
        $this->assertEquals('settings', $data['onceProps']['app-settings']['prop']);
        $this->assertNotNull($data['onceProps']['app-settings']['expiresAt']);
    }

    public function testForcefullyRefreshingAOncePropIncludesItInOnceProps(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit', [
                'settings' => Inertia::once(fn () => ['theme' => 'dark'])->fresh(),
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'settings' => ['theme' => 'dark'],
            ],
            'onceProps' => [
                'settings' => ['prop' => 'settings', 'expiresAt' => null],
            ],
        ]);
    }

    public function testOncePropIsIncludedInOncePropsByDefault(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit', [
                'settings' => Inertia::once(fn () => ['theme' => 'dark']),
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'settings' => ['theme' => 'dark'],
            ],
            'onceProps' => [
                'settings' => [
                    'prop' => 'settings',
                    'expiresAt' => null,
                ],
            ],
        ]);
    }

    public function testFlashDataIsFlashedToSessionOnRedirect(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->post('/flash-test', function () {
            return Inertia::flash(['message' => 'Success!'])->back();
        });

        $response = $this->post('/flash-test', [], [
            'X-Inertia' => 'true',
        ]);

        $response->assertRedirect();
        $this->assertEquals(['message' => 'Success!'], session('inertia.flash_data'));
    }

    public function testRenderWithFlashIncludesFlashInPage(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->post('/flash-test', function () {
            return Inertia::flash('type', 'success')
                ->render('User/Edit', ['user' => 'Jonathan'])
                ->flash(['message' => 'User updated!']);
        });

        $response = $this->post('/flash-test', [], [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'user' => 'Jonathan',
            ],
            'flash' => [
                'message' => 'User updated!',
                'type' => 'success',
            ],
        ]);

        // Flash data should not persist in session after being included in response
        $this->assertNull(session('inertia.flash_data'));
    }

    public function testRenderWithoutFlashDoesNotIncludeFlashKey(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/no-flash', function () {
            return Inertia::render('User/Edit', ['user' => 'Jonathan']);
        });

        $response = $this->get('/no-flash', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
        ]);
        $response->assertJsonMissing(['flash']);
    }

    public function testMultipleFlashCallsAreMerged(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->post('/create', function () {
            Inertia::flash('foo', 'value1');
            Inertia::flash('bar', 'value2');

            return Inertia::render('User/Show');
        });

        $response = $this->post('/create', [], ['X-Inertia' => 'true']);

        $response->assertJson([
            'flash' => [
                'foo' => 'value1',
                'bar' => 'value2',
            ],
        ]);
    }

    public function testSharedPropsTrackingCanBeDisabled(): void
    {
        config()->set('inertia.expose_shared_prop_keys', false);

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('app_name', 'My App');

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $data = $response->json();
        $this->assertArrayNotHasKey('sharedProps', $data);
        $this->assertSame('My App', $data['props']['app_name']);
    }

    public function testSharedPropsMetadataIncludesKeysFromMiddlewareShare(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit', [
                'user' => ['name' => 'Jonathan'],
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'props' => [
                'user' => ['name' => 'Jonathan'],
            ],
            'sharedProps' => ['errors'],
        ]);
    }

    public function testSharedPropsMetadataIncludesKeysFromInertiaShare(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('app_name', 'My App');

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'sharedProps' => ['errors', 'app_name'],
        ]);
    }

    public function testSharedPropsMetadataIncludesDotNotationKeysAsTopLevel(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('auth.user', ['name' => 'Jonathan']);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'sharedProps' => ['errors', 'auth'],
        ]);
    }

    public function testSharedPropsMetadataIncludesKeysFromShareOnce(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::shareOnce('permissions', fn () => ['admin' => true]);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'props' => [
                'permissions' => ['admin' => true],
            ],
            'sharedProps' => ['errors', 'permissions'],
        ]);
    }

    public function testSharedPropsMetadataIncludesKeysFromProvidesInertiaProperties(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share(new ExampleInertiaPropsProvider([
                'app_name' => 'My App',
                'locale' => 'en',
            ]));

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'props' => [
                'app_name' => 'My App',
                'locale' => 'en',
            ],
            'sharedProps' => ['errors', 'app_name', 'locale'],
        ]);
    }

    public function testSharedPropsMetadataIncludesPageSpecificOverrideKeys(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('auth', ['user' => null]);

            return Inertia::render('User/Edit', [
                'auth' => ['user' => ['name' => 'Jonathan']],
            ]);
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'props' => [
                'auth' => ['user' => ['name' => 'Jonathan']],
            ],
            'sharedProps' => ['errors', 'auth'],
        ]);
    }

    public function testSharedPropsMetadataWithMultipleShareCalls(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share('app_name', 'My App');
            Inertia::share('locale', 'en');
            Inertia::shareOnce('permissions', fn () => ['admin' => true]);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'sharedProps' => ['errors', 'app_name', 'locale', 'permissions'],
        ]);
    }

    public function testSharedPropsMetadataWithArrayShare(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::share([
                'flash' => fn () => ['message' => 'Hello'],
                'auth' => fn () => ['user' => ['name' => 'Jonathan']],
            ]);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'sharedProps' => ['errors', 'flash', 'auth'],
        ]);
    }

    public function testSharedPropsMetadataIncludesAlreadyLoadedOnceProps(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::shareOnce('permissions', fn () => ['admin' => true]);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Except-Once-Props' => 'permissions',
        ]);

        $response->assertSuccessful();
        $data = $response->json();

        // The once-prop value should be excluded from props since the client already has it
        $this->assertArrayNotHasKey('permissions', $data['props']);

        // But its key should still appear in the sharedProps metadata
        $this->assertContains('permissions', $data['sharedProps']);

        // And its onceProps metadata should also be preserved
        $this->assertArrayHasKey('permissions', $data['onceProps']);
    }

    public function testWithoutSsrRegistersPathsWithGateway(): void
    {
        Inertia::withoutSsr(['admin/*', 'nova/*']);

        $this->assertContains('admin/*', app(HttpGateway::class)->getExcludedPaths());
        $this->assertContains('nova/*', app(HttpGateway::class)->getExcludedPaths());
    }

    public function testWithoutSsrAcceptsString(): void
    {
        Inertia::withoutSsr('admin/*');

        $this->assertContains('admin/*', app(HttpGateway::class)->getExcludedPaths());
    }
}
