<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Passport\Client;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\CheckTokenForAnyScope;
use Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Hypervel\Passport\Passport;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\ServerRequest;

class ActingAsClientTest extends PassportTestCase
{
    public function testActingAsClientWhenTheRouteIsProtectedByCheckTokenMiddleware(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('/foo', function () {
            return 'bar';
        })->middleware(CheckToken::class);

        Passport::actingAsClient(new Client);

        $response = $this->get('/foo');
        $response->assertSuccessful();
        $response->assertSee('bar');
    }

    public function testActingAsClientWhenTheRouteIsProtectedByCheckTokenForAnyScope(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('/foo', function () {
            return 'bar';
        })->middleware(CheckTokenForAnyScope::class . ':testFoo');

        Passport::actingAsClient(new Client, ['testFoo']);

        $response = $this->get('/foo');
        $response->assertSuccessful();
        $response->assertSee('bar');
    }

    public function testActingAsClientSetsTheClientOnTheGuard(): void
    {
        Passport::actingAsClient($client = new Client);

        $this->assertSame($client, app('auth')->client());
    }

    public function testPreviouslyResolvedConsumersFollowRepeatedClientAndScopeChanges(): void
    {
        $guard = app('auth')->guard('passport');
        $server = $this->app->make(ResourceServer::class);
        $middleware = $this->app->make(EnsureClientIsResourceOwner::class);
        $router = $this->app->make(Registrar::class);
        $router->get('/first', fn (): array => ['client' => $guard->client()->getKey()])
            ->middleware(EnsureClientIsResourceOwner::using('first'));
        $router->get('/second', fn (): array => ['client' => $guard->client()->getKey()])
            ->middleware(EnsureClientIsResourceOwner::using('second'));

        foreach (['first', 'second'] as $name) {
            $client = (new Client)->forceFill(['id' => $name]);
            Passport::actingAsClient($client, [$name]);

            $this->assertSame($client, $guard->client());
            $this->assertSame($middleware, $this->app->make(EnsureClientIsResourceOwner::class));
            $validated = $server->validateAuthenticatedRequest(new ServerRequest('GET', '/'));
            $this->assertSame($name, $validated->getAttribute('oauth_client_id'));
            $this->assertSame($name, $validated->getAttribute('oauth_user_id'));
            $this->assertSame([$name], $validated->getAttribute('oauth_scopes'));
            $this->get('/' . $name)->assertOk()->assertJsonPath('client', $name);
        }

        $this->get('/first')->assertForbidden();
    }
}
