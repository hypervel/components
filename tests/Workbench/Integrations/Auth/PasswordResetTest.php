<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Auth\Notifications\ResetPassword;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Notification;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Tests\Workbench\Fixtures\Member;
use Hypervel\Tests\Workbench\Integrations\TestCase;
use Workbench\Database\Factories\UserFactory;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function testResetPasswordLinkScreenCanBeRendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function testResetPasswordLinkCanBeRequested(): void
    {
        Notification::fake();

        $user = UserFactory::new()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function testResetPasswordScreenCanBeRendered(): void
    {
        Notification::fake();

        $user = UserFactory::new()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification): bool {
            $response = $this->get('/reset-password/' . $notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function testPasswordCanBeResetWithValidToken(): void
    {
        Notification::fake();

        $user = UserFactory::new()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    #[WithEnv('AUTH_MODEL', Member::class)]
    public function testPasswordResetCyclesTheUserModelsOwnRememberToken(): void
    {
        Notification::fake();

        $this->createMembersTable();

        $member = Member::forceCreate([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->post('/forgot-password', ['email' => $member->email]);

        Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $notification) use ($member): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $member->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });

        $member->refresh();

        $this->assertTrue(Hash::check('new-password', $member->password));
        $this->assertNotNull($member->getRememberToken());
    }
}
