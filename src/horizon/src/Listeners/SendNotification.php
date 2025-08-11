<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\Lock;
use Hypervel\Support\Facades\Notification;

class SendNotification
{
    /**
     * Handle the event.
     */
    public function handle(mixed $event): void
    {
        $notification = $event->toNotification();

        if (! app(Lock::class)->get('notification:' . $notification->signature(), 300)) {
            return;
        }

        Notification::route('slack', Horizon::$slackWebhookUrl)
            ->route('nexmo', Horizon::$smsNumber)
            ->route('mail', Horizon::$email)
            ->notify($notification);
    }
}
