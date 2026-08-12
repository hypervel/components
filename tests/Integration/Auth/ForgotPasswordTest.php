<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Auth\Events\PasswordResetLinkSent;
use Hypervel\Auth\Notifications\ResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\Facades\Password;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Auth\Fixtures\AuthTestUser;
use Override;

#[WithMigration]
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');
        $config->set([
            'app.key' => '12345678901234567890123456789012',
            'auth.providers.users.model' => AuthTestUser::class,
            'auth.passwords.users.throttle' => 0,
            'auth.timebox_duration' => 0,
            'hashing.bcrypt.rounds' => 4,
        ]);
    }

    public function testResolvedBrokerFollowsEventFakesAndTheirRestoration(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $broker = Password::broker();
        $receivedUserIds = [];

        Event::listen(PasswordResetLinkSent::class, function (PasswordResetLinkSent $event) use (&$receivedUserIds): void {
            $receivedUserIds[] = $event->user->getAuthIdentifier();
        });

        Event::fakeFor(function () use ($broker, $user): void {
            $this->assertSame(
                PasswordBrokerContract::RESET_LINK_SENT,
                $broker->sendResetLink(['email' => $user->email]),
            );

            Event::assertDispatched(PasswordResetLinkSent::class);
        }, [PasswordResetLinkSent::class]);

        $this->assertSame([], $receivedUserIds);
        $this->assertSame(
            PasswordBrokerContract::RESET_LINK_SENT,
            $broker->sendResetLink(['email' => $user->email]),
        );
        $this->assertSame([$user->getAuthIdentifier()], $receivedUserIds);

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (ResetPassword $notification): bool => $notification->token !== '',
        );
    }

    public function testEventSwapDoesNotResolveAnUnusedPasswordManagerOrBroker(): void
    {
        $this->assertFalse($this->app->resolved('auth.password'));
        $this->assertFalse($this->app->resolved('auth.password.broker'));

        Event::fake();

        $this->assertFalse($this->app->resolved('auth.password'));
        $this->assertFalse($this->app->resolved('auth.password.broker'));
    }

    /**
     * Create a password-resettable user.
     */
    private function createUser(): AuthTestUser
    {
        return AuthTestUser::forceCreate([
            'name' => 'Auth User',
            'email' => 'auth@example.com',
            'password' => 'password',
        ]);
    }
}
