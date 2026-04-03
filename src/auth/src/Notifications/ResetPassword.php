<?php

declare(strict_types=1);

namespace Hypervel\Auth\Notifications;

use Closure;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Lang;
use SensitiveParameter;

class ResetPassword extends Notification
{
    /**
     * The callback that should be used to create the reset password URL.
     *
     * @var null|(Closure(mixed, string): string)
     */
    public static ?Closure $createUrlCallback = null;

    /**
     * The callback that should be used to build the mail message.
     *
     * @var null|(Closure(mixed, string): MailMessage)
     */
    public static ?Closure $toMailCallback = null;

    /**
     * Create a notification instance.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
    ) {
    }

    /**
     * Get the notification's channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        return $this->buildMailMessage($this->resetUrl($notifiable));
    }

    /**
     * Get the reset password notification mail message for the given URL.
     */
    protected function buildMailMessage(string $url): MailMessage
    {
        return (new MailMessage())
            ->subject(Lang::get('Reset your password'))
            ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
            ->action(Lang::get('Reset Password'), $url)
            ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire')]))
            ->line(Lang::get('If you did not request a password reset, no further action is required.'));
    }

    /**
     * Get the reset URL for the given notifiable.
     */
    protected function resetUrl(mixed $notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }

    /**
     * Set a callback that should be used when creating the reset password button URL.
     *
     * @param Closure(mixed, string): string $callback
     */
    public static function createUrlUsing(Closure $callback): void
    {
        static::$createUrlCallback = $callback;
    }

    /**
     * Set a callback that should be used when building the notification mail message.
     *
     * @param Closure(mixed, string): MailMessage $callback
     */
    public static function toMailUsing(Closure $callback): void
    {
        static::$toMailCallback = $callback;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$createUrlCallback = null;
        static::$toMailCallback = null;
    }
}
