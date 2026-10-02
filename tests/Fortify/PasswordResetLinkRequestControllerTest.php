<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Notifications\ResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\Facades\Password;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Mockery as m;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
#[WithConfig('auth.providers.users.model', User::class)]
class PasswordResetLinkRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheResetLinkRequestViewIsReturned(): void
    {
        Fortify::requestPasswordResetLinkView(fn (): string => 'hello world');

        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testResetLinkCanBeSuccessfullyRequested(): void
    {
        Notification::fake();

        $user = User::forceCreate(UserFactory::new()->raw(['email' => 'taylor@hypervel.org']));

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['email' => 'taylor@hypervel.org']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', trans(Password::RESET_LINK_SENT));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function testResetLinkRequestCanFail(): void
    {
        Notification::fake();

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['email' => 'taylor@laravel.com']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function testResetLinkRequestCanFailWithJson(): void
    {
        Notification::fake();

        $response = $this->from(url('/forgot-password'))
            ->postJson('/forgot-password', ['email' => 'taylor@laravel.com']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        Notification::assertNothingSent();
    }

    public function testResetLinkCanBeSuccessfullyRequestedWithCustomizedEmailField(): void
    {
        $this->app->make('config')->set('fortify.email', 'emailAddress');
        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);

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
        Notification::fake();

        $user = User::forceCreate(UserFactory::new()->raw(['email' => 'taylor@hypervel.org']));

        $response = $this->from(url('/forgot-password'))
            ->post('/forgot-password', ['email' => 'TAYLOR@hypervel.org']);

        $response->assertStatus(302);
        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', trans(Password::RESET_LINK_SENT));
        Notification::assertSentTo($user, ResetPassword::class);
    }
}
