<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Fortify\Contracts\RequestPasswordResetLinkViewResponse;
use Hypervel\Support\Facades\Password;
use Mockery as m;

class PasswordResetLinkRequestControllerTest extends TestCase
{
    public function testTheResetLinkRequestViewIsReturned(): void
    {
        $this->mock(RequestPasswordResetLinkViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testResetLinkCanBeSuccessfullyRequested(): void
    {
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('sendResetLink')->andReturn(Password::RESET_LINK_SENT);

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['email' => 'taylor@laravel.com']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', trans(Password::RESET_LINK_SENT));
    }

    public function testResetLinkRequestCanFail(): void
    {
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('sendResetLink')->andReturn(Password::INVALID_USER);

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['email' => 'taylor@laravel.com']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasErrors('email');
    }

    public function testResetLinkRequestCanFailWithJson(): void
    {
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('sendResetLink')->andReturn(Password::INVALID_USER);

        $response = $this->from(url('/forgot-password'))
            ->postJson('/forgot-password', ['email' => 'taylor@laravel.com']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function testResetLinkCanBeSuccessfullyRequestedWithCustomizedEmailField(): void
    {
        $this->app->make('config')->set('fortify.email', 'emailAddress');
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('sendResetLink')->andReturn(Password::RESET_LINK_SENT);

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['emailAddress' => 'taylor@laravel.com']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', trans(Password::RESET_LINK_SENT));
    }

    public function testCaseInsensitiveUsernamesCanBeUsed(): void
    {
        $this->app->make('config')->set('fortify.lowercase_usernames', true);
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('sendResetLink')->andReturn(Password::RESET_LINK_SENT);

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['email' => 'TAYLOR@laravel.com']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', trans(Password::RESET_LINK_SENT));
    }
}
