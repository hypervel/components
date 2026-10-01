<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Auth\Events\Lockout;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Workbench\Fixtures\Member;
use Hypervel\Tests\Workbench\Integrations\TestCase;
use Workbench\Database\Factories\UserFactory;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function testLoginScreenCanBeRendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function testUsersCanAuthenticateUsingTheLoginScreen(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function testUsersCanNotAuthenticateWithInvalidPassword(): void
    {
        $user = UserFactory::new()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function testUsersCanLogout(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function testUsersAreLoggedOutOfTheDefaultGuard(): void
    {
        $this->useMembersGuard();

        $member = Member::forceCreate([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAs($member)->post('/logout');

        $this->assertGuest('members');
        $response->assertRedirect('/');
    }

    #[WithConfig('rate-limiter.default', 'worker-array')]
    public function testUsersAreLockedOutAfterFiveFailedAttempts(): void
    {
        Event::fake([Lockout::class]);

        $user = UserFactory::new()->create();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringStartsWith('Too many login attempts.', session('errors')->first('email'));
        Event::assertDispatched(Lockout::class);
    }

    #[WithConfig('rate-limiter.default', 'worker-array')]
    public function testSuccessfulLoginClearsFailedAttempts(): void
    {
        $user = UserFactory::new()->create();

        for ($round = 0; $round < 2; ++$round) {
            for ($attempt = 0; $attempt < 4; ++$attempt) {
                $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
            }

            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasNoErrors();

            $this->assertAuthenticatedAs($user);

            $this->post('/logout');
        }
    }
}
