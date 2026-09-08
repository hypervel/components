<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Auth\Events\PasswordResetLinkSent;
use Hypervel\Auth\Notifications\ResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Routing\Router;
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

    /**
     * Define the password reset routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('password/reset/{token}', function (string $token): string {
            return 'Reset password!';
        })->name('password.reset');

        $router->get('custom/password/reset/{token}', function (string $token): string {
            return 'Custom reset password!';
        })->name('custom.password.reset');
    }

    public function testItCanSendForgotPasswordEmail(): void
    {
        Notification::fake();

        $user = $this->createUser();

        Password::broker()->sendResetLink([
            'email' => $user->email,
        ]);

        Notification::assertSentTo(
            $user,
            function (ResetPassword $notification, array $channels) use ($user): bool {
                $message = $notification->toMail($user);

                return $notification->token !== ''
                    && $message->actionUrl === route('password.reset', ['token' => $notification->token, 'email' => $user->email]);
            }
        );
    }

    public function testItCanTriggerPasswordResetSentEvent(): void
    {
        Event::fake([PasswordResetLinkSent::class]);

        $user = $this->createUser();

        Password::broker()->sendResetLink([
            'email' => $user->email,
        ]);

        Event::assertDispatched(PasswordResetLinkSent::class, function (PasswordResetLinkSent $event) use ($user): bool {
            $this->assertSame($user->getAuthIdentifier(), $event->user->getAuthIdentifier());

            return true;
        });
    }

    public function testItCanSendForgotPasswordEmailViaCreateUrlUsing(): void
    {
        Notification::fake();

        ResetPassword::createUrlUsing(function (mixed $user, string $token): string {
            return route('custom.password.reset', $token);
        });

        $user = $this->createUser();

        Password::broker()->sendResetLink([
            'email' => $user->email,
        ]);

        Notification::assertSentTo(
            $user,
            function (ResetPassword $notification, array $channels) use ($user): bool {
                $message = $notification->toMail($user);

                return $notification->token !== ''
                    && $message->actionUrl === route('custom.password.reset', ['token' => $notification->token]);
            }
        );
    }

    public function testItCanSendForgotPasswordEmailViaToMailUsing(): void
    {
        Notification::fake();

        ResetPassword::toMailUsing(function (mixed $notifiable, string $token): MailMessage {
            return (new MailMessage)
                ->subject(__('Reset your password'))
                ->line(__('You are receiving this email because we received a password reset request for your account.'))
                ->action(__('Reset Password'), route('custom.password.reset', $token))
                ->line(__('If you did not request a password reset, no further action is required.'));
        });

        $user = $this->createUser();

        Password::broker()->sendResetLink([
            'email' => $user->email,
        ]);

        Notification::assertSentTo(
            $user,
            function (ResetPassword $notification, array $channels) use ($user): bool {
                $message = $notification->toMail($user);

                return $notification->token !== ''
                    && $message->actionUrl === route('custom.password.reset', ['token' => $notification->token]);
            }
        );
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
