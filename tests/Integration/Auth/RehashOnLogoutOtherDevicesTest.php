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

#[WithMigration]
class RehashOnLogoutOtherDevicesTest extends TestCase
{
    use RefreshDatabase;

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'app.key' => '12345678901234567890123456789012',
            'auth.providers.users.model' => AuthTestUser::class,
            'hashing.bcrypt.rounds' => 5,
        ]);
    }

    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->post('/logout-other-devices', function (Request $request) {
            auth()->logoutOtherDevices($request->input('password'));

            return response()->noContent();
        })->middleware(['web', 'auth']);
    }

    public function testLogoutOtherDevicesRehashesThePersistedPassword(): void
    {
        $user = AuthTestUser::forceCreate([
            'name' => 'Auth User',
            'email' => 'auth@example.com',
            'password' => password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $originalHash = $user->password;

        $this->actingAs($user)
            ->post('/logout-other-devices', ['password' => 'password'])
            ->assertNoContent();

        $user->refresh();

        $this->assertNotSame($originalHash, $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }
}
