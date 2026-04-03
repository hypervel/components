<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class SlimSkeletonApplicationTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itCanAccessWelcomePageUsingRouteName()
    {
        $this->get(route('welcome'))
            ->assertOk();
    }

    #[Test]
    public function itThrowsExceptionWhenTryingToAccessAuthenticatedRoutesAsGuestWithoutLoginRouteName()
    {
        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('Route [login] not defined.');

        $this->withoutExceptionHandling()
            ->get(route('dashboard'));
    }

    #[Test]
    #[DefineRoute('defineLoginRoutes')]
    public function itCanBeRedirectedToLoginRouteNameWhenTryingToAccessAuthenticatedRoutes()
    {
        $this->get(route('dashboard'))
            ->assertRedirectToRoute('login');
    }

    /**
     * Define login routes setup.
     */
    protected function defineLoginRoutes(Router $router): void
    {
        $router->get('/login', fn () => 'Login')->name('login');
    }
}
