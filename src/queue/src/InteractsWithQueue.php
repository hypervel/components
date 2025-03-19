<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Queue\Exceptions\ManuallyFailedException;
use Hypervel\Queue\Jobs\FakeJob;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use Throwable;

trait InteractsWithQueue
{
    use InteractsWithTime;

    /**
     * The underlying queue job instance.
     */
    public ?JobContract $job = null;

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return $this->job ? $this->job->attempts() : 1;
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        if ($this->job) {
            $this->job->delete();
            return;
        }
    }

    /**
     * Fail the job from the queue.
     */
    public function fail(null|string|Throwable $exception = null): void
    {
        if (is_string($exception)) {
            $exception = new ManuallyFailedException($exception);
        }

        if ($exception instanceof Throwable || is_null($exception)) {
            if ($this->job) {
                $this->job->fail($exception);
                return;
            }
        } else {
            throw new InvalidArgumentException('The fail method requires a string or an instance of Throwable.');
        }
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(DateInterval|DateTimeInterface|int $delay = 0): void
    {
        $delay = $delay instanceof DateTimeInterface
            ? $this->secondsUntil($delay)
            : $delay;

        if ($this->job) {
            $this->job->release($delay);
            return;
        }
    }

    /**
     * Indicate that queue interactions like fail, delete, and release should be faked.
     */
    public function withFakeQueueInteractions(): static
    {
        $this->job = new FakeJob();

        return $this;
    }

    /**
     * Assert that the job was deleted from the queue.
     */
    public function assertDeleted(): static
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        PHPUnit::assertTrue(
            $this->job->isDeleted(),
            'Job was expected to be deleted, but was not.'
        );

        return $this;
    }

    /**
     * Assert that the job was not deleted from the queue.
     */
    public function assertNotDeleted(): static
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        PHPUnit::assertTrue(
            ! $this->job->isDeleted(),
            'Job was unexpectedly deleted.'
        );

        return $this;
    }

    /**
     * Assert that the job was manually failed.
     */
    public function assertFailed(): static
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        PHPUnit::assertTrue(
            $this->job->hasFailed(),
            'Job was expected to be manually failed, but was not.'
        );

        return $this;
    }

    /**
     * Assert that the job was not manually failed.
     */
    public function assertNotFailed(): static
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        PHPUnit::assertTrue(
            ! $this->job->hasFailed(),
            'Job was unexpectedly failed manually.'
        );

        return $this;
    }

    /**
     * Assert that the job was released back onto the queue.
     */
    public function assertReleased(null|DateInterval|DateTimeInterface|int $delay = null): static
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        $delay = $delay instanceof DateTimeInterface
            ? $this->secondsUntil($delay)
            : $delay;

        PHPUnit::assertTrue(
            $this->job->isReleased(),
            'Job was expected to be released, but was not.'
        );

        if (! is_null($delay)) {
            PHPUnit::assertSame(
                $delay,
                $this->job->releaseDelay,
                "Expected job to be released with delay of [{$delay}] seconds, but was released with delay of [{$this->job->releaseDelay}] seconds."
            );
        }

        return $this;
    }

    /**
     * Assert that the job was not released back onto the queue.
     */
    public function assertNotReleased(): static
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        PHPUnit::assertTrue(
            ! $this->job->isReleased(),
            'Job was unexpectedly released.'
        );

        return $this;
    }

    /**
     * Ensure that queue interactions have been faked.
     */
    private function ensureQueueInteractionsHaveBeenFaked(): void
    {
        if (! $this->job instanceof FakeJob) {
            throw new RuntimeException('Queue interactions have not been faked.');
        }
    }

    /**
     * Set the base queue job instance.
     */
    public function setJob(JobContract $job): static
    {
        $this->job = $job;

        return $this;
    }
}
