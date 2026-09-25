<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Middleware;

use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Http\Middleware\PrefersJsonResponses;
use Hypervel\Http\Response;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Testbench\TestCase;

class RequirePasswordTest extends TestCase
{
    public function testItCanGenerateDefinitionViaStaticMethod(): void
    {
        $signature = (string) RequirePassword::using('route.name');
        $this->assertSame('Hypervel\Auth\Middleware\RequirePassword:route.name', $signature);

        $signature = (string) RequirePassword::using('route.name', 100);
        $this->assertSame('Hypervel\Auth\Middleware\RequirePassword:route.name,100', $signature);

        $signature = (string) RequirePassword::using(passwordTimeoutSeconds: 100);
        $this->assertSame('Hypervel\Auth\Middleware\RequirePassword:,100', $signature);

        $signature = (string) RequirePassword::using(null, null);
        $this->assertSame('Hypervel\Auth\Middleware\RequirePassword:,', $signature);
    }

    public function testUserSeesTheWantedPageIfThePasswordWasRecentlyConfirmed(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::class]);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time()])->get('test-route');

        $response->assertOk();
        $response->assertSeeText('foobar');
    }

    public function testUserIsRedirectedToThePasswordConfirmRouteIfThePasswordWasNotRecentlyConfirmed(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('password-confirm', function (): Response {
            return new Response('foo');
        })->name('password.confirm');

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::class]);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 10801])->get('test-route');

        $response->assertStatus(302);
        $response->assertRedirect($this->app->make(UrlGenerator::class)->route('password.confirm'));
        $response->assertSessionHas('url.intended', $this->app->make(UrlGenerator::class)->to('test-route'));
    }

    public function testUserIsRedirectedToACustomRouteIfThePasswordWasNotRecentlyConfirmedAndTheCustomRouteIsSpecified(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('confirm', function (): Response {
            return new Response('foo');
        })->name('my-password.confirm');

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::class . ':my-password.confirm']);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 10801])->get('test-route');

        $response->assertStatus(302);
        $response->assertRedirect($this->app->make(UrlGenerator::class)->route('my-password.confirm'));
    }

    public function testPrefersJsonReturnsJsonResponseForWildcardAcceptInsteadOfRedirecting(): void
    {
        $this->withoutExceptionHandling();

        $this->app->make(HttpKernel::class)->prependMiddleware(PrefersJsonResponses::class);

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::class]);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 10801])
            ->get('test-route', ['Accept' => '*/*']);

        $response->assertStatus(423);
        $response->assertJson(['message' => 'Password confirmation required.']);
    }

    public function testPrefersJsonStillRedirectsWhenAcceptIsExplicitHtml(): void
    {
        $this->withoutExceptionHandling();

        $this->app->make(HttpKernel::class)->prependMiddleware(PrefersJsonResponses::class);

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('password-confirm', function (): Response {
            return new Response('foo');
        })->name('password.confirm');

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::class]);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 10801])
            ->get('test-route', ['Accept' => 'text/html']);

        $response->assertRedirect($this->app->make(UrlGenerator::class)->route('password.confirm'));
    }

    public function testAuthPasswordTimeoutIsConfigurable(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('password-confirm', function (): Response {
            return new Response('foo');
        })->name('password.confirm');

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::class]);

        $this->app->make(Repository::class)->set('auth.password_timeout', 500);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 495])->get('test-route');

        $response->assertOk();
        $response->assertSeeText('foobar');

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 501])->get('test-route');

        $response->assertStatus(302);
        $response->assertRedirect($this->app->make(UrlGenerator::class)->route('password.confirm'));
    }

    public function testNullTimeoutFromStaticMethodUsesConfiguredTimeout(): void
    {
        $this->withoutExceptionHandling();

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $router->get('test-route', function (): Response {
            return new Response('foobar');
        })->middleware([StartSession::class, RequirePassword::using('password.confirm', null)]);

        $response = $this->withSession(['auth.password_confirmed_at_web' => time() - 10])->get('test-route');

        $response->assertOk();
        $response->assertSeeText('foobar');
    }
}
