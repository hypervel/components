<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Testbench\TestCase;

class AssertRedirectToRouteTest extends TestCase
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
            ->get('named-route')
            ->name('named-route');

        $this->router
            ->get('named-route-with-param/{param}')
            ->name('named-route-with-param');

        $this->router
            ->get('')
            ->name('route-with-empty-uri');

        $this->urlGenerator = $this->app->make(UrlGenerator::class);
    }

    public function testAssertRedirectToRouteWithRouteName(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->route('named-route'));
        });

        $this->get('test-route')
            ->assertRedirectToRoute('named-route');
    }

    public function testAssertRedirectToRouteWithRouteNameAndParams(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->route('named-route-with-param', 'hello'));
        });

        $this->router->get('test-route-with-extra-param', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->route('named-route-with-param', [
                'param' => 'foo',
                'extra' => 'another',
            ]));
        });

        $this->get('test-route')
            ->assertRedirectToRoute('named-route-with-param', 'hello');

        $this->get('test-route-with-extra-param')
            ->assertRedirectToRoute('named-route-with-param', [
                'param' => 'foo',
                'extra' => 'another',
            ]);
    }

    public function testAssertRedirectToRouteWithRouteNameAndParamsWhenRouteUriIsEmpty(): void
    {
        $this->router->get('test-route', function (): RedirectResponse {
            return new RedirectResponse($this->urlGenerator->route('route-with-empty-uri', ['foo' => 'bar']));
        });

        $this->get('test-route')
            ->assertRedirectToRoute('route-with-empty-uri', ['foo' => 'bar']);
    }
}
