<?php

declare(strict_types=1);

namespace Hypervel\Auth\Listeners;

use Hypervel\Auth\Events\Registered;
use Hypervel\Contracts\Auth\MustVerifyEmail;

class SendEmailVerificationNotification
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        if ($event->user instanceof MustVerifyEmail && ! $event->user->hasVerifiedEmail()) {
            $event->user->sendEmailVerificationNotification();
        }
    }
}
