<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Http\Controllers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Routing\Router;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Workbench\Fixtures\MakesBrowserRequests;
use Hypervel\Workbench\WorkbenchServiceProvider;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
#[WithConfig('database.default', 'testing')]
#[WithMigration]
class WorkbenchControllerTest extends TestCase
{
    use MakesBrowserRequests;
    use RefreshDatabase;

    /**
     * Define routes setup.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('/workbench', ['uses' => fn (): string => 'hello world']);
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
    public function itCanGetCurrentUserInformation(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->assertGuest('web')
            ->actingAs($user, 'web')
            ->get('/_workbench/user/web');

        $response->assertOk()->assertExactJson([
            'id' => $user->getKey(),
            'className' => $user::class,
        ]);
    }

    #[Test]
    public function itCanGetCurrentUserInformationWithoutAuthenticatedUserReturnEmptyArray(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->assertGuest('web')
            ->get('/_workbench/user/web');

        $response->assertOk()->assertExactJson([]);
    }

    #[Test]
    public function itCanAuthenticateAUser(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->assertGuest('web')
            ->get("/_workbench/login/{$user->getKey()}/web");

        $response->assertRedirect('/');

        $this->assertAuthenticated('web')
            ->assertAuthenticatedAs($user);
    }

    #[Test]
    public function itCanAuthenticateAUserUsingEmail(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->assertGuest('web')
            ->get("/_workbench/login/{$user->email}/web");

        $response->assertRedirect('/');

        $this->assertAuthenticated('web')
            ->assertAuthenticatedAs($user);
    }

    #[Test]
    public function itReturnsNotFoundForAnUnknownUserWithoutEndingTheCurrentSession(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->get("/_workbench/login/{$user->getKey()}/web")->assertRedirect('/');

        $this->withSessionCookieFrom($response)
            ->get('/_workbench/login/missing@example.com/web')
            ->assertNotFound();

        $this->withSessionCookieFrom($response)
            ->get('/_workbench/user/web')
            ->assertExactJson(['id' => $user->getKey(), 'className' => $user::class]);
    }

    #[Test]
    public function itCanDeauthenticateAUser(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->assertGuest('web')
            ->actingAs($user, 'web')
            ->get('/_workbench/logout/web');

        $response->assertRedirect('/');

        $this->assertGuest('web');
    }

    #[Test]
    public function itCanAutomaticallyAuthenticateAUser(): void
    {
        $user = UserFactory::new()->create();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/workbench', 'user' => $user->getKey(), 'guard' => 'web'],
        ]));

        $response = $this->assertGuest('web')->get('/_workbench/');

        $response->assertRedirect('/workbench');

        $this->assertAuthenticated('web')
            ->assertAuthenticatedAs($user);
    }

    #[Test]
    public function itCanAutomaticallyAuthenticateAUserUsingEmail(): void
    {
        $user = UserFactory::new()->create();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/workbench', 'user' => $user->email, 'guard' => 'web'],
        ]));

        $response = $this->assertGuest('web')->get('/_workbench/');

        $response->assertRedirect('/workbench');

        $this->assertAuthenticated('web')
            ->assertAuthenticatedAs($user);
    }

    #[Test]
    public function itCanAutomaticallyDeauthenticateAUser(): void
    {
        $user = UserFactory::new()->create();

        $this->instance(ConfigContract::class, new Config([
            'workbench' => ['start' => '/workbench', 'user' => null, 'guard' => 'web'],
        ]));

        $response = $this->assertGuest('web')
            ->actingAs($user, 'web')
            ->get('/_workbench');

        $response->assertRedirect('/workbench');

        $this->assertGuest('web');
    }
}
