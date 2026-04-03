<?php

declare(strict_types=1);

namespace Hypervel\Auth\Notifications;

use Closure;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Lang;
use Hypervel\Support\Facades\URL;

class VerifyEmail extends Notification
{
    /**
     * The callback that should be used to create the verify email URL.
     *
     * @var null|(Closure(mixed): string)
     */
    public static ?Closure $createUrlCallback = null;

    /**
     * The callback that should be used to build the mail message.
     *
     * @var null|(Closure(mixed, string): MailMessage)
     */
    public static ?Closure $toMailCallback = null;

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
        $verificationUrl = $this->verificationUrl($notifiable);

        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $verificationUrl);
        }

        return $this->buildMailMessage($verificationUrl);
    }

    /**
     * Get the verify email notification mail message for the given URL.
     */
    protected function buildMailMessage(string $url): MailMessage
    {
        return (new MailMessage())
            ->subject(Lang::get('Verify your email address'))
            ->line(Lang::get('Please click the button below to verify your email address.'))
            ->action(Lang::get('Verify Email Address'), $url)
            ->line(Lang::get('If you did not create an account, no further action is required.'));
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(mixed $notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }

    /**
     * Set a callback that should be used when creating the email verification URL.
     *
     * @param Closure(mixed): string $callback
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
