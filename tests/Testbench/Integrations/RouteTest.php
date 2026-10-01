<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Exception;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\Limit;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\ExampleController;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class RouteTest extends TestCase
{
    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->middleware('web')->get('web/test', fn () => 'Test using web');
        $router->middleware('api')->get('api/test', fn () => 'Test using api');
        $router->middleware(['api', 'throttle:api'])->get('api/throttled', fn (): string => 'Test using api throttle');

        $router->domain('api.localhost')
            ->group(function (Router $router) {
                $router->get('hello', fn () => 'hello from api');
            });

        $router->get('hello', ['as' => 'hi', 'uses' => fn () => 'hello world']);

        $router->get('goodbye', fn () => 'goodbye world')->name('bye');

        $router->group(['prefix' => 'boss'], function (Router $router) {
            $router->get('hello', ['as' => 'boss.hi', 'uses' => fn () => 'hello boss']);

            $router->get('goodbye', fn () => 'goodbye boss')->name('boss.bye');
        });

        $router->resource('foo', ExampleController::class);
    }

    /**
     * Define the test's own api rate limiter.
     */
    protected function defineApiRateLimiter(ApplicationContract $app): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(7)->by($request->ip()));
    }

    #[Test]
    public function itCanResolveWebGroupRoute(): void
    {
        $crawler = $this->call('GET', 'web/test');

        $this->assertEquals('Test using web', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveApiGroupRoute(): void
    {
        $crawler = $this->call('GET', 'api/test');

        $this->assertEquals('Test using api', $crawler->getContent());
    }

    #[Test]
    #[WithConfig('rate-limiter.default', 'worker-array')]
    public function itDefinesTheDefaultApiRateLimiter(): void
    {
        $this->get('api/throttled')
            ->assertOk()
            ->assertSee('Test using api throttle')
            ->assertHeader('X-RateLimit-Limit', '60');
    }

    #[Test]
    #[DataProvider('defaultApiRateLimiterKeys')]
    public function itKeysTheDefaultApiRateLimiterByTheAuthIdentifier(?Authenticatable $user, string $key): void
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $request->setUserResolver(static fn (): ?Authenticatable => $user);

        $this->assertSame($key, RateLimiter::limiter('api')($request)->key);
    }

    /**
     * Get request users and the default api rate limiter key for each.
     *
     * @return iterable<string, array{?Authenticatable, string}>
     */
    public static function defaultApiRateLimiterKeys(): iterable
    {
        yield 'custom identifier name' => [new CustomIdentifierUser(['uuid' => 'user-uuid']), 'user-uuid'];
        yield 'zero identifier' => [new GenericUser(['id' => 0]), '0'];
        yield 'guest' => [null, '203.0.113.5'];
    }

    #[Test]
    #[WithConfig('rate-limiter.default', 'worker-array')]
    #[DefineEnvironment('defineApiRateLimiter')]
    public function itKeepsTheApiRateLimiterDefinedByTheTest(): void
    {
        $this->get('api/throttled')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '7');
    }

    #[Test]
    public function itCanResolveGetRoutes(): void
    {
        $crawler = $this->call('GET', 'hello');

        $this->assertEquals('hello world', $crawler->getContent());

        $crawler = $this->call('GET', 'goodbye');

        $this->assertEquals('goodbye world', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveGetRoutesWithPrefixes(): void
    {
        $crawler = $this->call('GET', 'boss/hello');

        $this->assertEquals('hello boss', $crawler->getContent());

        $crawler = $this->call('GET', 'boss/goodbye');

        $this->assertEquals('goodbye boss', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveResourceController(): void
    {
        $response = $this->call('GET', 'foo');

        $response->assertStatus(200);
        $this->assertEquals('ExampleController@index', $response->getContent());
    }

    #[Test]
    public function itCanResolveDomainRoute(): void
    {
        $response = $this->get('http://api.localhost/hello');

        $response->assertStatus(200);
        $this->assertEquals('hello from api', $response->getContent());
    }

    #[Test]
    public function itCanResolveNameRoutes(): void
    {
        $this->app->make(Router::class)->get('passthrough', fn () => route('bye'))->name('pass');

        $response = $this->call('GET', route('pass'));

        $response->assertStatus(200);
        $this->assertEquals('http://localhost/goodbye', $response->getContent());
    }

    #[Test]
    public function itCanHandleRouteThrowingException(): void
    {
        $this->app->make(Router::class)->get('bad-route', fn () => throw new Exception('Route error!'))->name('bad');

        $response = $this->call('GET', route('bad'));

        $response->assertStatus(500);
    }
}

class CustomIdentifierUser extends GenericUser
{
    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'uuid';
    }
}
