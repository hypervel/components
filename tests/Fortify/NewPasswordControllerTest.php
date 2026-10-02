<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Events\PasswordReset;
use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Contracts\ResetsUserPasswords;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Password;
use Hypervel\Testbench\Attributes\WithMigration;
use Mockery as m;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class NewPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheNewPasswordViewIsReturned(): void
    {
        Fortify::resetPasswordView(fn (): string => 'hello world');

        $response = $this->get('/reset-password/token');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testPasswordCanBeReset(): void
    {
        Event::fake([PasswordReset::class]);

        $user = User::forceCreate(UserFactory::new()->raw(['email' => 'taylor@hypervel.org']));
        $token = Password::broker()->createToken($user);

        $this->mock(ResetsUserPasswords::class)
            ->shouldReceive('reset')
            ->once()
            ->with(m::on(fn (Model $resetUser): bool => $resetUser->is($user)), m::type('array'));

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => $token,
            'email' => 'taylor@hypervel.org',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(Fortify::redirects('password-reset', route('login')));
        $this->assertGuest();
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);
        Event::assertDispatched(PasswordReset::class);
    }

    public function testPasswordResetCanFail(): void
    {
        User::forceCreate(UserFactory::new()->raw(['email' => 'taylor@hypervel.org']));

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => 'token',
            'email' => 'taylor@hypervel.org',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
    }

    public function testPasswordResetCanFailWithJson(): void
    {
        User::forceCreate(UserFactory::new()->raw(['email' => 'taylor@hypervel.org']));

        $response = $this->postJson('/reset-password', [
            'token' => 'token',
            'email' => 'taylor@hypervel.org',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function testPasswordCanBeResetWithCustomizedEmailAddressField(): void
    {
        $this->app->make('config')->set('fortify.email', 'emailAddress');
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $user = User::forceCreate(UserFactory::new()->raw());
        $rememberToken = $user->remember_token;

        $this->mock(ResetsUserPasswords::class)
            ->shouldReceive('reset')
            ->once()
            ->with($user, m::type('array'));

        $broker->shouldReceive('reset')->once()->andReturnUsing(function (array $input, callable $callback) use ($user): string {
            $callback($user, 'password');

            return Password::PASSWORD_RESET;
        });

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => 'token',
            'emailAddress' => 'taylor@laravel.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(Fortify::redirects('password-reset', route('login')));
        $this->assertGuest();
        $this->assertNotSame($rememberToken, $user->remember_token);
    }

    public function testPasswordIsRequired(): void
    {
        $response = $this->post('/reset-password', [
            'token' => 'token',
            'email' => 'taylor@laravel.com',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['password']);
    }

    public function testCaseInsensitiveUsernamesCanBeUsed(): void
    {
        $this->app->make('config')->set('fortify.lowercase_usernames', true);

        $user = User::forceCreate(UserFactory::new()->raw(['email' => 'john.doe@example.com']));
        $token = Password::broker()->createToken($user);

        $this->mock(ResetsUserPasswords::class)
            ->shouldReceive('reset')
            ->once()
            ->with(m::on(fn (Model $resetUser): bool => $resetUser->is($user)), m::type('array'));

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => $token,
            'email' => 'John.Doe@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(Fortify::redirects('password-reset', route('login')));
    }
}
