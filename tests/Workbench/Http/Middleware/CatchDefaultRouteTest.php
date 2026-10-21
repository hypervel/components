<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Http\Middleware;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Workbench\Fixtures\MakesBrowserRequests;
use Hypervel\Workbench\WorkbenchServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[WithConfig('database.default', 'testing')]
#[WithMigration]
class CatchDefaultRouteTest extends TestCase
{
    use MakesBrowserRequests;
    use RefreshDatabase;

    /**
     * Define routes setup.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('/workbench', ['uses' => function (): string {
            return 'hello world';
        }]);
    }

    /**
     * Get package providers.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            WorkbenchServiceProvider::class,
        ];
    }

    #[Test]
    public function itWouldRedirectToWorkbenchPath(): void
    {
        $user = UserFactory::new()->create();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/workbench', 'user' => $user->getKey(), 'guard' => 'web'],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertRedirect('/_workbench');
    }

    #[Test]
    public function itWouldShowDefaultPage(): void
    {
        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => true],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertOk();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => true, 'welcome' => true],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertOk();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => false, 'welcome' => true],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertOk();
    }

    #[Test]
    public function itWouldNotRedirectToWorkbenchPathIfConfigurationDoesntRequiresIt(): void
    {
        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => false],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertNotFound();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => true, 'welcome' => false],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertNotFound();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => false, 'welcome' => false],
        ]));

        $this->assertGuest('web')->get('/')
            ->assertNotFound();
    }

    #[Test]
    public function itWouldNotRedirectToWorkbenchPathOnPathOtherThanRoot(): void
    {
        $user = UserFactory::new()->create();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/workbench', 'user' => $user->getKey(), 'guard' => 'web'],
        ]));

        $this->assertGuest('web')->get('/workbench')
            ->assertOk();

        $this->assertGuest('web');
    }

    #[Test]
    public function itLogsInThePreviewUserBeforeAnAuthenticatedRootRoute(): void
    {
        $reached = false;

        $this->app->make(Router::class)
            ->middleware(['web', 'auth'])
            ->get('/', function () use (&$reached): string {
                $reached = true;

                return 'Protected root';
            });

        $this->usePreviewUser(start: '/', guard: 'web');

        $response = $this->get('/')->assertRedirect('/_workbench');

        $this->assertFalse($reached);

        $response = $this->withSessionCookieFrom($response)->get('/_workbench')->assertRedirect('/');

        $this->withSessionCookieFrom($response)->get('/')->assertOk()->assertSee('Protected root');
        $this->assertTrue($reached);
    }

    #[Test]
    public function itShowsTheWelcomePageAfterLoggingInOnAnUnmatchedRoot(): void
    {
        $this->usePreviewUser(start: '/', guard: 'web');

        $response = $this->get('/')->assertRedirect('/_workbench');
        $response = $this->withSessionCookieFrom($response)->get('/_workbench')->assertRedirect('/');

        $this->withSessionCookieFrom($response)->get('/')->assertOk()->assertSee('Hello Hypervel!');
    }

    #[Test]
    public function itRedirectsToTheStartPathAfterLoggingInOnAnUnmatchedRoot(): void
    {
        $this->usePreviewUser(start: '/dashboard', guard: 'web');

        $response = $this->get('/')->assertRedirect('/_workbench');
        $response = $this->withSessionCookieFrom($response)->get('/_workbench')->assertRedirect('/dashboard');

        $this->withSessionCookieFrom($response)->get('/')->assertRedirect('/dashboard');
    }

    #[Test]
    #[DefineEnvironment('defineAdminGuard')]
    public function itChecksTheConfiguredGuardForThePreviewUser(): void
    {
        $this->app->make(Router::class)
            ->middleware(['web', 'auth:admin'])
            ->get('/', fn (): string => 'Admin root');

        $this->usePreviewUser(start: '/', guard: 'admin');

        $response = $this->get('/')->assertRedirect('/_workbench');
        $response = $this->withSessionCookieFrom($response)->get('/_workbench')->assertRedirect('/');

        $this->withSessionCookieFrom($response)->get('/')->assertOk()->assertSee('Admin root');
    }

    #[Test]
    #[DefineEnvironment('registerApplicationRootRoute')]
    public function itKeepsAnApplicationRootRouteRegisteredBeforeTheRootFallback(): void
    {
        $this->get('/')->assertOk()->assertSee('Application root');
        $this->get('/missing')->assertNotFound();
        $this->assertMatchedRoutes(root: '/', missing: null);
    }

    #[Test]
    #[DefineRoute('registerApplicationRootRoute')]
    public function itKeepsAnApplicationRootRouteRegisteredAfterTheRootFallback(): void
    {
        $this->get('/')->assertOk()->assertSee('Application root');
        $this->get('/missing')->assertNotFound();
        $this->assertMatchedRoutes(root: '/', missing: null);
    }

    #[Test]
    #[DefineEnvironment('registerApplicationFallback')]
    public function itKeepsAnApplicationFallbackRegisteredBeforeTheRootFallback(): void
    {
        $this->get('/')->assertOk()->assertSee('Application fallback');
        $this->get('/missing')->assertOk()->assertSee('Application fallback');
        $this->assertMatchedRoutes(root: '{fallbackPlaceholder}', missing: '{fallbackPlaceholder}');
    }

    #[Test]
    #[DefineEnvironment('registerDiscoveredFallback')]
    public function itKeepsAPackageFallbackDiscoveredOnceTheApplicationHasBooted(): void
    {
        $this->get('/')->assertOk()->assertSee('Application fallback');
        $this->get('/missing')->assertOk()->assertSee('Application fallback');
        $this->assertMatchedRoutes(root: '{fallbackPlaceholder}', missing: '{fallbackPlaceholder}');
    }

    #[Test]
    #[DefineEnvironment('registerNotFoundApplicationRootRoute')]
    public function itKeepsANotFoundResponseFromAnApplicationRootRoute(): void
    {
        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/', 'install' => true, 'welcome' => true],
        ]));

        $this->get('/')->assertNotFound()->assertDontSee('Hello Hypervel!');
        $this->assertMatchedRoutes(root: '/', missing: null);
    }

    /**
     * Define a second session guard.
     */
    protected function defineAdminGuard(ApplicationContract $app): void
    {
        $app->make('config')->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    }

    /**
     * Register an application route for "/" in the web group.
     */
    protected function registerApplicationRootRoute(ApplicationContract|Router $app): void
    {
        $router = $app instanceof Router ? $app : $app->make(Router::class);

        $router->middleware('web')->get('/', fn (): string => 'Application root');
    }

    /**
     * Register an application fallback route.
     */
    protected function registerApplicationFallback(ApplicationContract $app): void
    {
        $app->make(Router::class)->fallback(fn (): string => 'Application fallback');
    }

    /**
     * Register an application fallback the way Testbench discovers Workbench routes.
     *
     * Route discovery registers its callback before the providers boot and loads
     * workbench/routes/web.php once the application has booted.
     */
    protected function registerDiscoveredFallback(ApplicationContract $app): void
    {
        $app->booted(function (ApplicationContract $app): void {
            $this->registerApplicationFallback($app);
        });
    }

    /**
     * Register an application route for "/" that responds with a 404.
     */
    protected function registerNotFoundApplicationRootRoute(ApplicationContract $app): void
    {
        $app->make(Router::class)->middleware('web')->get('/', static fn (): never => abort(404));
    }

    /**
     * Configure Workbench to log in a preview user.
     */
    private function usePreviewUser(string $start, string $guard): void
    {
        $user = UserFactory::new()->create();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => $start, 'user' => $user->getKey(), 'guard' => $guard, 'install' => true],
        ]));
    }

    /**
     * Assert which routes match "/" and "/missing", with and without route compilation.
     */
    private function assertMatchedRoutes(string $root, ?string $missing): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->getRoutes();

        foreach ([$routes, $routes->toCompiledRouteCollection($router, $this->app)] as $collection) {
            $this->assertSame($root, $collection->match(Request::create('/'))->uri());

            if ($missing !== null) {
                $this->assertSame($missing, $collection->match(Request::create('/missing'))->uri());

                continue;
            }

            try {
                $collection->match(Request::create('/missing'));
                $this->fail('No route should match [/missing].');
            } catch (NotFoundHttpException) {
                // Unrelated paths still produce a 404.
            }
        }
    }
}
