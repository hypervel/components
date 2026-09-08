<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Auth\Notifications\ResetPassword;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\Facades\Password;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Auth\Fixtures\AuthTestUser;
use Override;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

#[WithMigration]
class ForgotPasswordWithoutDefaultRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Define the password broker test environment.
     */
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
     * Define the custom password reset route.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('custom/password/reset/{token}', function (string $token): string {
            return 'Custom reset password!';
        })->name('custom.password.reset');
    }

    public function testItCannotSendForgotPasswordEmail(): void
    {
        $this->expectExceptionObject(new RouteNotFoundException('Route [password.reset] not defined.'));

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
                    && $message->actionUrl === route('custom.password.reset', ['token' => $notification->token, 'email' => $user->email]);
            }
        );
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
