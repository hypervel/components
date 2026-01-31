<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use DateTime;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Collection;
use Throwable;

class SendQueuedNotifications implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The notifiable entities that should receive the notification.
     */
    public Collection $notifiables;

    /**
     * The notification to be sent.
     */
    public mixed $notification;

    /**
     * All of the channels to send the notification to.
     */
    public ?array $channels = null;

    /**
     * The number of times the job may be attempted.
     */
    public ?int $tries = null;

    /**
     * The number of seconds the job can run before timing out.
     */
    public ?int $timeout = null;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public ?int $maxExceptions = null;

    /**
     * Indicates if the job should be encrypted.
     */
    public bool $shouldBeEncrypted = false;

    /**
     * Create a new job instance.
     */
    public function __construct(mixed $notifiables, mixed $notification, ?array $channels = null)
    {
        $this->channels = $channels;
        $this->notification = $notification;
        $this->notifiables = $this->wrapNotifiables($notifiables);
        $this->tries = property_exists($notification, 'tries') ? $notification->tries : null;
        $this->timeout = property_exists($notification, 'timeout') ? $notification->timeout : null;
        $this->maxExceptions = property_exists($notification, 'maxExceptions') ? $notification->maxExceptions : null;

        if ($notification instanceof ShouldQueueAfterCommit) {
            $this->afterCommit = true;
        } else {
            $this->afterCommit = property_exists($notification, 'afterCommit') ? $notification->afterCommit : null;
        }

        $this->shouldBeEncrypted = $notification instanceof ShouldBeEncrypted;
    }

    /**
     * Wrap the notifiable(s) in a collection.
     */
    protected function wrapNotifiables(mixed $notifiables): Collection
    {
        if ($notifiables instanceof Collection) {
            return $notifiables;
        }
        if ($notifiables instanceof Model) {
            return EloquentCollection::wrap($notifiables);
        }

        return Collection::wrap($notifiables);
    }

    /**
     * Send the notifications.
     */
    public function handle(ChannelManager $manager): void
    {
        $manager->sendNow($this->notifiables, $this->notification, $this->channels);
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return get_class($this->notification);
    }

    /**
     * Call the failed method on the notification instance.
     */
    public function failed(Throwable $e): void
    {
        if (method_exists($this->notification, 'failed')) {
            $this->notification->failed($e);
        }
    }

    /**
     * Get the number of seconds before a released notification will be available.
     */
    public function backoff(): mixed
    {
        if (! method_exists($this->notification, 'backoff') && ! isset($this->notification->backoff)) {
            return null;
        }

        return $this->notification->backoff ?? $this->notification->backoff();
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): ?DateTime
    {
        if (! method_exists($this->notification, 'retryUntil') && ! isset($this->notification->retryUntil)) {
            return null;
        }

        return $this->notification->retryUntil ?? $this->notification->retryUntil();
    }

    /**
     * Prepare the instance for cloning.
     */
    public function __clone()
    {
        $this->notifiables = clone $this->notifiables;
        $this->notification = clone $this->notification;
    }
}
