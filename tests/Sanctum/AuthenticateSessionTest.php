<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Http\Middleware\AuthenticateSession;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\TestUser;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => TestUser::class,
            ],
        ]);
    }

    public function testUnionOfSanctumGuardsSessionGuardsIsChecked(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => ['web'],
        ]);
        $config->set('auth.guards.admin-api', [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => ['admin'],
        ]);

        $this->app->make('auth')->forgetGuards();
        $this->app->make('auth')->guard('admin')->setUser($this->user('new-password'));

        $request = $this->requestWithSession();
        $request->session()->put('password_hash_admin', 'old-password');

        try {
            $this->middleware()->handle($request, fn () => new Response('next'));
            $this->fail('Expected authentication exception was not thrown.');
        } catch (AuthenticationException $exception) {
            $this->assertSame('Unauthenticated.', $exception->getMessage());
            $this->assertSame(['admin', 'sanctum'], $exception->guards());
            $this->assertFalse($request->session()->has('password_hash_admin'));
        }
    }

    public function testSanctumEntryWithoutSessionGuardsContributesNothing(): void
    {
        $this->app->make('config')->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $this->app->make('auth')->forgetGuards();

        $request = $this->requestWithSession();

        $response = $this->middleware()->handle($request, fn () => new Response('next'));

        $this->assertSame('next', $response->getContent());
    }

    public function testMalformedSessionGuardsEntriesAreSkippedByUnion(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.guards.bad-api', [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => 'web',
        ]);
        $config->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => [123, '', 'admin'],
        ]);

        $auth = $this->app->make('auth');
        $auth->forgetGuards();
        $auth->guard('web')->setUser($this->user('web-password'));
        $auth->guard('admin')->setUser($this->user('admin-password'));

        $request = $this->requestWithSession();
        $request->session()->put('password_hash_web', 'stale-web-password');
        $request->session()->put('password_hash_admin', 'admin-password');

        $response = $this->middleware()->handle($request, fn () => new Response('next'));

        $this->assertSame('next', $response->getContent());
        $this->assertSame('stale-web-password', $request->session()->get('password_hash_web'));
        $this->assertSame('admin-password', $request->session()->get('password_hash_admin'));
    }

    private function middleware(): AuthenticateSession
    {
        return $this->app->make(AuthenticateSession::class);
    }

    private function requestWithSession(): Request
    {
        $request = new Request;
        $request->setHypervelSession(new Store('name', new ArraySessionHandler(1)));

        return $request;
    }

    private function user(string $password): AuthenticatableContract
    {
        return new class($password) implements AuthenticatableContract {
            public function __construct(
                private readonly string $password,
            ) {
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): int
            {
                return 1;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return $this->password;
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken(string $value): void
            {
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };
    }
}
