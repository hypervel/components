<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\Inertia\AlwaysProp;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Routing\Route as RouteInstance;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Session;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\Inertia\Fixtures\CustomUrlResolverMiddleware;
use Hypervel\Tests\Inertia\Fixtures\ExampleMiddleware;
use Hypervel\Tests\Inertia\Fixtures\SsrExceptMiddleware;
use Hypervel\Tests\Inertia\Fixtures\WithAllErrorsMiddleware;
use LogicException;
use PHPUnit\Framework\Attributes\After;

/**
 * @internal
 * @coversNothing
 */
class MiddlewareTest extends TestCase
{
    #[After]
    public function cleanupPublicFolder(): void
    {
        (new Filesystem)->cleanDirectory(public_path());
    }

    public function testNoResponseValueByDefaultMeansAutomaticallyRedirectingBackForInertiaRequests(): void
    {
        $fooCalled = false;
        Route::middleware(Middleware::class)->put('/', function () use (&$fooCalled) {
            $fooCalled = true;
        });

        $response = $this
            ->from('/foo')
            ->put('/', [], [
                'X-Inertia' => 'true',
                'Content-Type' => 'application/json',
            ]);

        $response->assertRedirect('/foo');
        $response->assertStatus(303);
        $this->assertTrue($fooCalled);
    }

    public function testNoResponseValueCanBeCustomizedByOverridingTheMiddlewareMethod(): void
    {
        Route::middleware(ExampleMiddleware::class)->get('/', function () {
            // Do nothing..
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An empty Inertia response was returned.');

        $this
            ->withoutExceptionHandling()
            ->from('/foo')
            ->get('/', [
                'X-Inertia' => 'true',
                'Content-Type' => 'application/json',
            ]);
    }

    public function testNoResponseMeansNoResponseForNonInertiaRequests(): void
    {
        $fooCalled = false;
        Route::middleware(Middleware::class)->put('/', function () use (&$fooCalled) {
            $fooCalled = true;
        });

        $response = $this
            ->from('/foo')
            ->put('/', [], [
                'Content-Type' => 'application/json',
            ]);

        $response->assertNoContent(200);
        $this->assertTrue($fooCalled);
    }

    public function testTheVersionIsOptional(): void
    {
        $this->prepareMockEndpoint();

        $response = $this->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson(['component' => 'User/Edit']);
    }

    public function testTheVersionCanBeANumber(): void
    {
        $this->prepareMockEndpoint($version = 1597347897973);

        $response = $this->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]);

        $response->assertSuccessful();
        $response->assertJson(['component' => 'User/Edit']);
    }

    public function testTheVersionCanBeAString(): void
    {
        $this->prepareMockEndpoint($version = 'foo-version');

        $response = $this->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]);

        $response->assertSuccessful();
        $response->assertJson(['component' => 'User/Edit']);
    }

    public function testItWillInstructInertiaToReloadOnAVersionMismatch(): void
    {
        $this->prepareMockEndpoint('1234');

        $response = $this->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '4321',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', $this->baseUrl);
        self::assertEmpty($response->getContent());
    }

    public function testTheUrlCanBeResolvedWithACustomResolver(): void
    {
        $this->prepareMockEndpoint(middleware: new CustomUrlResolverMiddleware);

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'url' => '/my-custom-url',
        ]);
    }

    public function testValidationErrorsAreRegisteredAsOfDefault(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $this->assertInstanceOf(AlwaysProp::class, Inertia::getShared('errors'));
        });

        $this->withoutExceptionHandling()->get('/');
    }

    public function testValidationErrorsCanBeEmpty(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $errors = Inertia::getShared('errors')();

            $this->assertIsObject($errors);
            $this->assertEmpty(get_object_vars($errors));
        });

        $this->withoutExceptionHandling()->get('/');
    }

    public function testValidationErrorsAreMappedToStringsByDefault(): void
    {
        Session::put('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'name' => ['The name field is required.'],
            'email' => ['Not a valid email address.', 'Another email error.'],
        ])));

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $errors = Inertia::getShared('errors')();

            $this->assertIsObject($errors);
            $this->assertSame('The name field is required.', $errors->name);
            $this->assertSame('Not a valid email address.', $errors->email);
        });

        $this->withoutExceptionHandling()->get('/');
    }

    public function testValidationErrorsCanRemainMultiplePerField(): void
    {
        Session::put('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'name' => ['The name field is required.'],
            'email' => ['Not a valid email address.', 'Another email error.'],
        ])));

        Route::middleware([StartSession::class, WithAllErrorsMiddleware::class])->get('/', function () {
            $errors = Inertia::getShared('errors')();

            $this->assertIsObject($errors);
            $this->assertSame(['The name field is required.'], $errors->name);
            $this->assertSame(
                ['Not a valid email address.', 'Another email error.'],
                $errors->email
            );
        });

        $this->withoutExceptionHandling()->get('/');
    }

    public function testValidationErrorsWithNamedErrorBagsAreScoped(): void
    {
        Session::put('errors', (new ViewErrorBag)->put('example', new MessageBag([
            'name' => 'The name field is required.',
            'email' => 'Not a valid email address.',
        ])));

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $errors = Inertia::getShared('errors')();

            $this->assertIsObject($errors);
            $this->assertSame('The name field is required.', $errors->example->name);
            $this->assertSame('Not a valid email address.', $errors->example->email);
        });

        $this->withoutExceptionHandling()->get('/');
    }

    public function testDefaultValidationErrorsCanBeOverwritten(): void
    {
        Session::put('errors', (new ViewErrorBag)->put('example', new MessageBag([
            'name' => 'The name field is required.',
            'email' => 'Not a valid email address.',
        ])));

        $this->prepareMockEndpoint(null, ['errors' => 'foo']);
        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertJson([
            'props' => [
                'errors' => 'foo',
            ],
        ]);
    }

    public function testValidationErrorsAreScopedToErrorBagHeader(): void
    {
        Session::put('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'name' => 'The name field is required.',
            'email' => 'Not a valid email address.',
        ])));

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            $errors = Inertia::getShared('errors')();

            $this->assertIsObject($errors);
            $this->assertSame('The name field is required.', $errors->example->name);
            $this->assertSame('Not a valid email address.', $errors->example->email);
        });

        $this->withoutExceptionHandling()->get('/', ['X-Inertia-Error-Bag' => 'example']);
    }

    public function testMiddlewareCanChangeTheRootViewViaAProperty(): void
    {
        $this->prepareMockEndpoint(null, [], new class extends Middleware {
            protected string $rootView = 'welcome';
        });

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('welcome');
    }

    public function testMiddlewareCanChangeTheRootViewByOverridingTheRootviewMethod(): void
    {
        $this->prepareMockEndpoint(null, [], new class extends Middleware {
            public function rootView(Request $request): string
            {
                return 'welcome';
            }
        });

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('welcome');
    }

    public function testDetermineTheVersionByAHashOfTheAssetUrl(): void
    {
        config(['app.asset_url' => $url = 'https://example.com/assets']);

        $this->prepareMockEndpoint(middleware: new Middleware);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewHas('page.version', hash('xxh128', $url));
    }

    public function testDetermineTheVersionByAHashOfTheViteManifest(): void
    {
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(public_path('build'));
        $filesystem->put(
            public_path('build/manifest.json'),
            $contents = json_encode(['vite' => true])
        );

        $this->prepareMockEndpoint(middleware: new Middleware);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewHas('page.version', hash('xxh128', $contents));
    }

    public function testDetermineTheVersionByAHashOfTheMixManifest(): void
    {
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(public_path());
        $filesystem->put(
            public_path('mix-manifest.json'),
            $contents = json_encode(['mix' => true])
        );

        $this->prepareMockEndpoint(middleware: new Middleware);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewHas('page.version', hash('xxh128', $contents));
    }

    public function testMiddlewareShareOnce(): void
    {
        $middleware = new class extends Middleware {
            public function shareOnce(Request $request): array
            {
                return [
                    'permissions' => fn () => ['admin' => true],
                    'settings' => Inertia::once(fn () => ['theme' => 'dark'])
                        ->as('app-settings')
                        ->until(60),
                ];
            }
        };

        Route::middleware(StartSession::class)->get('/', function (Request $request) use ($middleware) {
            return $middleware->handle($request, function ($request) {
                return Inertia::render('User/Edit')->toResponse($request);
            });
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'props' => [
                'permissions' => ['admin' => true],
                'settings' => ['theme' => 'dark'],
            ],
            'onceProps' => [
                'permissions' => ['prop' => 'permissions', 'expiresAt' => null],
                'app-settings' => ['prop' => 'settings'],
            ],
        ]);
        $this->assertNotNull($response->json('onceProps.app-settings.expiresAt'));
    }

    public function testMiddlewareShareAndShareOnceAreMerged(): void
    {
        $middleware = new class extends Middleware {
            public function share(Request $request): array
            {
                return array_merge(parent::share($request), [
                    'flash' => fn () => ['message' => 'Hello'],
                ]);
            }

            public function shareOnce(Request $request): array
            {
                return array_merge(parent::shareOnce($request), [
                    'permissions' => fn () => ['admin' => true],
                ]);
            }
        };

        Route::middleware(StartSession::class)->get('/', function (Request $request) use ($middleware) {
            return $middleware->handle($request, function ($request) {
                return Inertia::render('User/Edit')->toResponse($request);
            });
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertSuccessful();
        $response->assertJson([
            'props' => [
                'flash' => ['message' => 'Hello'],
                'permissions' => ['admin' => true],
            ],
            'onceProps' => [
                'permissions' => ['prop' => 'permissions', 'expiresAt' => null],
            ],
        ]);
    }

    public function testFlashDataIsPreservedOnNonInertiaRedirect(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->get('/action', function () {
            Inertia::flash('message', 'Success!');

            return redirect('/dashboard');
        });

        Route::middleware([StartSession::class, Middleware::class])->get('/dashboard', function () {
            return Inertia::render('Dashboard');
        });

        $response = $this->get('/action');
        $response->assertRedirect('/dashboard');

        $response = $this->get('/dashboard', ['X-Inertia' => 'true']);
        $response->assertJson([
            'flash' => ['message' => 'Success!'],
        ]);
    }

    public function testRedirectWithHashFragmentReturns409ForInertiaRequests(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->get('/action', function () {
            return redirect('/article#section');
        });

        $response = $this->get('/action', [
            'X-Inertia' => 'true',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Redirect', $this->baseUrl . '/article#section');
        self::assertEmpty($response->getContent());
    }

    public function testRedirectWithoutHashFragmentIsNotIntercepted(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->post('/action', function () {
            return redirect('/article');
        });

        $response = $this->post('/action', [], [
            'X-Inertia' => 'true',
        ]);

        $response->assertRedirect('/article');
        $response->assertStatus(302);
    }

    public function testRedirectWithHashFragmentIsNotInterceptedForNonInertiaRequests(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->get('/action', function () {
            return redirect('/article#section');
        });

        $response = $this->get('/action');

        $response->assertRedirect($this->baseUrl . '/article#section');
        $response->assertStatus(302);
    }

    public function testPostRedirectWithHashFragmentReturns409ForInertiaRequests(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->post('/action', function () {
            return redirect('/article#section');
        });

        $response = $this->post('/action', [], [
            'X-Inertia' => 'true',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Redirect', $this->baseUrl . '/article#section');
    }

    public function testRedirectWithHashFragmentIsNotInterceptedForPrefetchRequests(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->get('/action', function () {
            return redirect('/article#section');
        });

        $response = $this->get('/action', [
            'X-Inertia' => 'true',
            'Purpose' => 'prefetch',
        ]);

        $response->assertRedirect($this->baseUrl . '/article#section');
    }

    public function testMiddlewareRegistersSsrExceptPaths(): void
    {
        $middleware = new SsrExceptMiddleware;

        Route::middleware(StartSession::class)->get('/admin/dashboard', function (Request $request) use ($middleware) {
            return $middleware->handle($request, function ($request) {
                return Inertia::render('Admin/Dashboard')->toResponse($request);
            });
        });

        $this->get('/admin/dashboard');

        $this->assertContains('admin/*', app(HttpGateway::class)->getExcludedPaths());
        $this->assertContains('nova/*', app(HttpGateway::class)->getExcludedPaths());
    }

    public function testVersionIsCachedForWorkerLifetime(): void
    {
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(public_path('build'));
        $filesystem->put(
            public_path('build/manifest.json'),
            $originalContents = json_encode(['v1' => true])
        );

        $middleware = new Middleware;
        $request = Request::create('/');

        $firstVersion = $middleware->version($request);
        $this->assertSame(hash('xxh128', $originalContents), $firstVersion);

        // Change the manifest — cached version should still be returned
        $filesystem->put(
            public_path('build/manifest.json'),
            json_encode(['v2' => true])
        );

        $secondVersion = $middleware->version($request);
        $this->assertSame($firstVersion, $secondVersion);
    }

    public function testVersionCacheResetsAfterFlushState(): void
    {
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(public_path('build'));
        $filesystem->put(
            public_path('build/manifest.json'),
            json_encode(['v1' => true])
        );

        $middleware = new Middleware;
        $request = Request::create('/');

        $firstVersion = $middleware->version($request);

        Middleware::flushState();

        $filesystem->put(
            public_path('build/manifest.json'),
            $newContents = json_encode(['v2' => true])
        );

        $secondVersion = $middleware->version($request);
        $this->assertSame(hash('xxh128', $newContents), $secondVersion);
        $this->assertNotSame($firstVersion, $secondVersion);
    }

    /**
     * @param array<string, mixed> $shared
     */
    private function prepareMockEndpoint(int|string|null $version = null, array $shared = [], ?Middleware $middleware = null): RouteInstance
    {
        if (is_null($middleware)) {
            $middleware = new ExampleMiddleware($version, $shared);
        }

        return Route::middleware(StartSession::class)->get('/', function (Request $request) use ($middleware) {
            return $middleware->handle($request, function ($request) {
                return Inertia::render('User/Edit', ['user' => ['name' => 'Jonathan']])->toResponse($request);
            });
        });
    }
}
