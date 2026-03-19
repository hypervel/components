<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use DateTime;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Mail\Factory as MailFactory;
use Hypervel\Contracts\Mail\Mailable as MailableContract;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\InteractsWithQueue;
use Throwable;

class SendQueuedMailable
{
    use InteractsWithQueue;
    use Queueable;
    use ReadsQueueAttributes;

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

        $this->connection = $this->getAttributeValue($mailable, Connection::class, 'connection');
        $this->maxExceptions = $this->getAttributeValue($mailable, MaxExceptions::class, 'maxExceptions');
        $this->queue = $this->getAttributeValue($mailable, QueueAttribute::class, 'queue');
        $this->shouldBeEncrypted = $mailable instanceof ShouldBeEncrypted;
        $this->timeout = $this->getAttributeValue($mailable, Timeout::class, 'timeout');
        $this->tries = $this->getAttributeValue($mailable, Tries::class, 'tries');
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
        $backoff = $this->getAttributeValue($this->mailable, Backoff::class, 'backoff');

        if (method_exists($this->mailable, 'backoff')) {
            $backoff = $this->mailable->backoff();
        }

        return $backoff;
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
