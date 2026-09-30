<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Auth\Fixtures\AuthTestUser;
use Override;
use Symfony\Component\HttpFoundation\Response;

#[WithMigration]
class RehashOnLogoutOtherDevicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Configure the authentication provider and password hashing.
     */
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'app.key' => '12345678901234567890123456789012',
            'auth.providers.users.model' => AuthTestUser::class,
            'hashing.bcrypt.rounds' => 5,
        ]);
    }

    /**
     * Define the login and authenticated session routes.
     */
    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->post('/login', function (Request $request): Response {
            if (! auth()->attempt($request->only('email', 'password'))) {
                return response()->noContent(401);
            }

            auth()->logoutOtherDevices($request->input('password'));

            return response()->noContent();
        })->middleware(['web', 'auth.session'])->name('login');

        $router->get('/authenticated', fn (): Response => response()->noContent())
            ->middleware(['web', 'auth', 'auth.session']);
    }

    public function testLogoutOtherDevicesRehashesThePersistedPassword(): void
    {
        $user = AuthTestUser::forceCreate([
            'name' => 'Auth User',
            'email' => 'auth@example.com',
            'password' => password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $originalHash = $user->password;

        $response = $this->post('/login', ['email' => 'auth@example.com', 'password' => 'password'])
            ->assertNoContent();

        $user->refresh();

        $this->assertNotSame($originalHash, $user->password);
        $this->assertTrue(Hash::check('password', $user->password));

        $cookie = $response->getCookie($this->app->make('config')->get('session.cookie'));
        $this->assertNotNull($cookie);
        auth()->guard()->forgetUser();

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/authenticated')
            ->assertNoContent();
        $this->assertAuthenticatedAs($user);
    }
}
