<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Fortify\Contracts\ResetPasswordViewResponse;
use Hypervel\Fortify\Contracts\ResetsUserPasswords;
use Hypervel\Fortify\Fortify;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Password;
use Mockery as m;
use Workbench\App\Models\User;

class NewPasswordControllerTest extends TestCase
{
    public function testTheNewPasswordViewIsReturned(): void
    {
        $this->mock(ResetPasswordViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $response = $this->get('/reset-password/token');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testPasswordCanBeReset(): void
    {
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $user = m::mock(TestNewPasswordUser::class)->makePartial();

        $user->shouldReceive('setRememberToken')->once();
        $user->shouldReceive('save')->once();

        $updater = $this->mock(ResetsUserPasswords::class);
        $updater->shouldReceive('reset')->once()->with($user, m::type('array'));

        $broker->shouldReceive('reset')->andReturnUsing(function ($input, $callback) use ($user) {
            $callback($user, 'password');

            return Password::PASSWORD_RESET;
        });

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => 'token',
            'email' => 'taylor@laravel.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(Fortify::redirects('password-reset', route('login')));
        $this->assertNull(Auth::getUser());
    }

    public function testPasswordResetCanFail(): void
    {
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('reset')->andReturnUsing(function ($input, $callback) {
            return Password::INVALID_TOKEN;
        });

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => 'token',
            'email' => 'taylor@laravel.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
    }

    public function testPasswordResetCanFailWithJson(): void
    {
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('reset')->andReturnUsing(function ($input, $callback) {
            return Password::INVALID_TOKEN;
        });

        $response = $this->postJson('/reset-password', [
            'token' => 'token',
            'email' => 'taylor@laravel.com',
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

        $user = m::mock(TestNewPasswordUser::class)->makePartial();

        $user->shouldReceive('setRememberToken')->once();
        $user->shouldReceive('save')->once();

        $updater = $this->mock(ResetsUserPasswords::class);
        $updater->shouldReceive('reset')->once()->with($user, m::type('array'));

        $broker->shouldReceive('reset')->andReturnUsing(function ($input, $callback) use ($user) {
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
        $this->assertNull(Auth::getUser());
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
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $user = m::mock(TestNewPasswordUser::class)->makePartial();

        $user->shouldReceive('setRememberToken')->once();
        $user->shouldReceive('save')->once();

        $updater = $this->mock(ResetsUserPasswords::class);
        $updater->shouldReceive('reset')->once()->with($user, m::type('array'));

        $broker->shouldReceive('reset')
            ->once()
            ->with(
                m::on(fn ($credentials) => $credentials['email'] === 'john.doe@example.com'),
                m::type('callable')
            )
            ->andReturnUsing(function ($input, $callback) use ($user) {
                $callback($user, 'password');

                return Password::PASSWORD_RESET;
            });

        $response = $this->withoutExceptionHandling()->post('/reset-password', [
            'token' => 'token',
            'email' => 'John.Doe@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(Fortify::redirects('password-reset', route('login')));
        $this->assertNull(Auth::getUser());
    }
}

class TestNewPasswordUser extends User
{
}
