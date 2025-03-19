<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use DateTime;
use Hypervel\Bus\Queueable;
use Hypervel\Mail\Contracts\Factory as MailFactory;
use Hypervel\Mail\Contracts\Mailable as MailableContract;
use Hypervel\Queue\Contracts\ShouldBeEncrypted;
use Hypervel\Queue\Contracts\ShouldQueueAfterCommit;
use Hypervel\Queue\InteractsWithQueue;
use Throwable;

class SendQueuedMailable
{
    use Queueable;
    use InteractsWithQueue;

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
     *
     * @param Mailable $mailable the mailable message instance
     */
    public function __construct(
        public MailableContract $mailable
    ) {
        if ($mailable instanceof ShouldQueueAfterCommit) {
            $this->afterCommit = true;
        } else {
            $this->afterCommit = property_exists($mailable, 'afterCommit') ? $mailable->afterCommit : null;
        }

        $this->connection = property_exists($mailable, 'connection') ? $mailable->connection : null;
        $this->maxExceptions = property_exists($mailable, 'maxExceptions') ? $mailable->maxExceptions : null;
        $this->queue = property_exists($mailable, 'queue') ? $mailable->queue : null;
        $this->shouldBeEncrypted = $mailable instanceof ShouldBeEncrypted;
        $this->timeout = property_exists($mailable, 'timeout') ? $mailable->timeout : null;
        $this->tries = property_exists($mailable, 'tries') ? $mailable->tries : null;
    }

    /**
     * Handle the queued job.
     */
    public function handle(MailFactory $factory): void
    {
        $this->mailable->send($factory);
    }

    /**
     * Get the number of seconds before a released mailable will be available.
     */
    public function backoff(): mixed
    {
        if (! method_exists($this->mailable, 'backoff') && ! isset($this->mailable->backoff)) {
            return null;
        }

        return $this->mailable->backoff ?? $this->mailable->backoff();
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): ?DateTime
    {
        if (! method_exists($this->mailable, 'retryUntil') && ! isset($this->mailable->retryUntil)) {
            return null;
        }

        return $this->mailable->retryUntil ?? $this->mailable->retryUntil();
    }

    /**
     * Call the failed method on the mailable instance.
     */
    public function failed(Throwable $e): void
    {
        if (method_exists($this->mailable, 'failed')) {
            $this->mailable->failed($e);
        }
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return get_class($this->mailable);
    }

    /**
     * Prepare the instance for cloning.
     */
    public function __clone(): void
    {
        $this->mailable = clone $this->mailable;
    }
}
