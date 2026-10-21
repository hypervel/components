<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Passport\Client;
use Hypervel\Passport\Contracts\AuthorizationViewResponse;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Events\AuthorizationUserResolved;
use Hypervel\Passport\Http\Controllers\AuthorizationController;
use Hypervel\Passport\Http\Middleware\CreateFreshApiToken;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\ServerSettings;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Session\Store;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\User;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    /**
     * Register Passport without needing credential tables for these isolation checks.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Configure separate user providers and reusable signing material.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $material = (new SigningKeyFactory)->definition();
        $app->make('config')->set([
            'passport.private_key' => $material['private_key'],
            'passport.public_key' => $material['public_key'],
            'auth.guards.first' => ['driver' => 'session', 'provider' => 'users'],
            'auth.guards.second' => ['driver' => 'session', 'provider' => 'staff'],
            'auth.providers.users.model' => User::class,
            'auth.providers.staff' => ['driver' => 'eloquent', 'model' => User::class],
        ]);
    }

    public function testSharedGuardKeepsExplicitUsersAndClientsInTheirOwnCoroutines(): void
    {
        $guard = $this->app->make('auth')->guard('passport');
        $authenticate = function (int $identifier) use ($guard): array {
            $guard->setUser((new User)->forceFill(['id' => $identifier]));
            $guard->setClient((new Client)->forceFill(['id' => "client-{$identifier}"]));
            usleep(5000);

            return [$guard->user()->getKey(), $guard->client()->getKey()];
        };

        $this->assertSame([[1, 'client-1'], [2, 'client-2']], parallel([
            fn (): array => $authenticate(1),
            fn (): array => $authenticate(2),
        ]));
        $this->assertNull($guard->user());
        $this->assertNull($guard->client());
    }

    public function testSharedMiddlewareKeepsItsGuardAndCookieAttributesAcrossAYield(): void
    {
        Passport::bindCookiesUsing(static fn (Request $request): string => $request->getHost());
        StartSession::configureSessionCookieUsing(static function (Request $request, array $config): array {
            $config['path'] = '/' . $request->getHost();

            return $config;
        });
        $middleware = $this->app->make(CreateFreshApiToken::class);
        $key = new Key($this->app->make(ServerFactory::class)->cookieKey(), 'HS256');
        $users = [
            'first' => (new User)->forceFill(['id' => 1]),
            'second' => (new User)->forceFill(['id' => 2]),
        ];
        $issueCookie = function (string $guard) use ($middleware, $key, $users): array {
            $request = Request::create("https://{$guard}.example/fresh");
            $request->setUserResolver(static fn (?string $name): User => $users[$name]);
            $session = new Store('passport-isolation', new ArraySessionHandler(120));
            $session->start();
            $request->setHypervelSession($session);
            RequestContext::set($request);

            $response = $middleware->handle($request, static function (): Response {
                usleep(5000);

                return new Response('Content');
            }, $guard);
            $cookie = $response->headers->getCookies()[0];
            $claims = JWT::decode($cookie->getValue(), $key);
            $this->assertSame($session->token(), $claims->csrf);

            return [$claims->sub, $claims->provider, $claims->bind, $cookie->getPath()];
        };

        $this->assertSame([
            [1, 'users', 'first.example', '/first.example'],
            [2, 'staff', 'second.example', '/second.example'],
        ], parallel([
            fn (): array => $issueCookie('first'),
            fn (): array => $issueCookie('second'),
        ]));
    }

    public function testConcurrentConsentResponsesKeepTheirOwnParameters(): void
    {
        Passport::authorizationView(static fn (array $parameters): array => $parameters);
        $render = function (string $owner): string {
            $response = $this->app->make(AuthorizationViewResponse::class)->withParameters(['owner' => $owner]);
            usleep(5000);

            return $response->toResponse(Request::create('/oauth/authorize'))->getContent();
        };

        $this->assertSame(['{"owner":"first"}', '{"owner":"second"}'], parallel([
            fn (): string => $render('first'),
            fn (): string => $render('second'),
        ]));
    }

    public function testSharedAuthorizationControllerUsesEachRequestsCurrentDefaultGuard(): void
    {
        config(['passport.guard' => null]);
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000004_create_oauth_clients_table.php')->up();
        $client = Client::forceCreate([
            'name' => 'Client', 'revoked' => false, 'secret' => 'secret',
            'grant_types' => ['authorization_code'], 'redirect_uris' => ['https://client.example/callback'],
        ]);
        Event::listen(AuthorizationUserResolved::class, static function (): void {
            usleep(5000);
        });
        Passport::authorizationView(static fn (array $parameters): array => [
            'user' => $parameters['user']->getAuthIdentifier(),
            'provider' => $parameters['request']->session()->get('passport.authorizations.' . $parameters['authToken'] . '.provider'),
        ]);
        $controller = $this->app->make(AuthorizationController::class);
        $authorize = function (string $guard, int $userId) use ($controller, $client): array {
            $auth = $this->app->make('auth');
            $auth->shouldUse($guard);
            $auth->guard()->setUser((new User)->forceFill(['id' => $userId]));
            $parameters = [
                'response_type' => 'code', 'client_id' => $client->id,
                'redirect_uri' => 'https://client.example/callback', 'prompt' => 'consent',
            ];
            $request = Request::create('https://auth.example/oauth/authorize', 'GET', $parameters);
            $session = new Store('passport-guard-isolation', new ArraySessionHandler(120));
            $session->start();
            $request->setHypervelSession($session);
            RequestContext::set($request);
            usleep(5000);

            $response = $controller->authorize(
                (new ServerRequest('GET', $request->getUri()))->withQueryParams($parameters),
                $request,
                new PsrResponse,
                $this->app->make(AuthorizationViewResponse::class),
            );

            return json_decode($response->toResponse($request)->getContent(), true);
        };

        $this->assertSame([
            ['user' => 1, 'provider' => 'users'],
            ['user' => 2, 'provider' => 'staff'],
        ], parallel([
            fn (): array => $authorize('first', 1),
            fn (): array => $authorize('second', 2),
        ]));
    }

    public function testConcurrentSettingsUseTheirOwnServersScopesAndIssuer(): void
    {
        $material = (new SigningKeyFactory)->definition();
        $key = new ServerKey($material['public_key'], $material['private_key']);
        $this->app->instance(ServerSettingsResolver::class, new class($key) implements ServerSettingsResolver {
            /**
             * Reuse immutable key material for independent server settings.
             */
            public function __construct(protected ServerKey $key)
            {
            }

            /**
             * Select settings from the current request's host.
             */
            public function current(): ?string
            {
                return RequestContext::get()->getHost();
            }

            /**
             * Load the scopes and issuer for the selected host.
             */
            public function resolve(string $identifier): ServerSettings
            {
                return new ServerSettings(
                    [$this->key],
                    scopes: [$identifier => $identifier],
                    issuer: 'https://' . $identifier,
                    resources: ['https://' . $identifier . '/api' => $identifier],
                );
            }
        });
        $factory = $this->app->make(ServerFactory::class);
        $resolve = function (string $host) use ($factory): array {
            RequestContext::set(Request::create('https://' . $host));
            $server = $factory->authorizationServer();
            usleep(5000);
            $this->assertSame($server, $factory->authorizationServer());
            $this->assertTrue(Passport::hasResource('https://' . $host . '/api'));
            $other = $host === 'first.example' ? 'second.example' : 'first.example';
            $this->assertFalse(Passport::hasResource('https://' . $other . '/api'));

            return [$factory->settings()->issuer, Passport::scopeIds(), $server];
        };

        [$first, $second] = parallel([
            fn (): array => $resolve('first.example'),
            fn (): array => $resolve('second.example'),
        ]);

        $this->assertSame(['https://first.example', ['first.example']], array_slice($first, 0, 2));
        $this->assertSame(['https://second.example', ['second.example']], array_slice($second, 0, 2));
        $this->assertNotSame($first[2], $second[2]);
    }
}
