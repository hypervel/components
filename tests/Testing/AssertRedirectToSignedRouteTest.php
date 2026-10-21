<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Testbench\TestCase;

class AssertRedirectToSignedRouteTest extends TestCase
{
    private Registrar $router;

    private UrlGenerator $urlGenerator;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->router = $this->app->make(Registrar::class);

        $this->router
            ->get('signed-route')
            ->name('signed-route');

        $this->router
            ->get('signed-route-with-param/{param}')
            ->name('signed-route-with-param');

        $this->urlGenerator = $this->app->make(UrlGenerator::class);
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set(['app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
    }

    public function testAssertRedirectToSignedRouteWithoutRouteName(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->signedRoute('signed-route'));
        });

        $this->get('test-route')
            ->assertRedirectToSignedRoute();
    }

    public function testAssertRedirectToSignedRouteWithRouteName(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->signedRoute('signed-route'));
        });

        $this->get('test-route')
            ->assertRedirectToSignedRoute('signed-route');
    }

    public function testAssertRedirectToSignedRouteWithRouteNameAndParams(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->signedRoute('signed-route-with-param', 'hello'));
        });

        $this->router->get('test-route-with-extra-param', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->signedRoute('signed-route-with-param', [
                'param' => 'foo',
                'extra' => 'another',
            ]));
        });

        $this->get('test-route')
            ->assertRedirectToSignedRoute('signed-route-with-param', 'hello');

        $this->get('test-route-with-extra-param')
            ->assertRedirectToSignedRoute('signed-route-with-param', [
                'param' => 'foo',
                'extra' => 'another',
            ]);
    }

    public function testAssertRedirectToSignedRouteWithRouteNameToTemporarySignedRoute(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->temporarySignedRoute('signed-route', 60));
        });

        $this->get('test-route')
            ->assertRedirectToSignedRoute('signed-route');
    }
}
