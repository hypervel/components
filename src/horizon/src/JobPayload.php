<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use ArrayAccess;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Horizon\Contracts\Silenced;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Support\Arr;
use Illuminate\Events\CallQueuedListener;

class JobPayload implements ArrayAccess
{
    /**
     * The decoded payload array.
     */
    public array $decoded;

    /**
     * Create a new raw job payload instance.
     *
     * @param string $value the raw payload string
     */
    public function __construct(
        public string $value
    ) {
        $this->decoded = json_decode($value, true);
    }

    /**
     * Get the job ID from the payload.
     */
    public function id(): string
    {
        return $this->decoded['uuid'] ?? $this->decoded['id'];
    }

    /**
     * Get the job tags from the payload.
     */
    public function tags(): array
    {
        return Arr::get($this->decoded, 'tags', []);
    }

    /**
     * Determine if the job is a retry of a previous job.
     */
    public function isRetry(): bool
    {
        return isset($this->decoded['retry_of']);
    }

    /**
     * Get the ID of the job this job is a retry of.
     */
    public function retryOf(): ?string
    {
        return $this->decoded['retry_of'] ?? null;
    }

    /**
     * Determine if the job has been silenced.
     */
    public function isSilenced(): bool
    {
        return $this->decoded['silenced'] ?? false;
    }

    /**
     * Prepare the payload for storage on the queue by adding tags, etc.
     */
    public function prepare(mixed $job): static
    {
        return $this->set([
            'type' => $this->determineType($job),
            'tags' => $this->determineTags($job),
            'silenced' => $this->shouldBeSilenced($job),
            'pushedAt' => str_replace(',', '.', (string) microtime(true)),
        ]);
    }

    /**
     * Get the "type" of job being queued.
     */
    protected function determineType(mixed $job): string
    {
        return match (true) {
            $job instanceof BroadcastEvent => 'broadcast',
            $job instanceof CallQueuedListener => 'event',
            $job instanceof SendQueuedMailable => 'mail',
            $job instanceof SendQueuedNotifications => 'notification',
            default => 'job',
        };
    }

    /**
     * Get the appropriate tags for the job.
     */
    protected function determineTags(mixed $job): array
    {
        return array_merge(
            $this->decoded['tags'] ?? [],
            ! $job || is_string($job) ? [] : Tags::for($job)
        );
    }

    /**
     * Determine if the underlying job class should be silenced.
     */
    protected function shouldBeSilenced(mixed $job): bool
    {
        if (! $job) {
            return false;
        }

        $underlyingJob = $this->underlyingJob($job);

        $jobClass = is_string($underlyingJob) ? $underlyingJob : get_class($underlyingJob);

        return in_array($jobClass, config('horizon.silenced', []))
            || is_a($jobClass, Silenced::class, true);
    }

    /**
     * Get the underlying queued job.
     */
    protected function underlyingJob(mixed $job): mixed
    {
        return match (true) {
            $job instanceof BroadcastEvent => $job->event,
            $job instanceof CallQueuedListener => $job->class,
            $job instanceof SendQueuedMailable => $job->mailable,
            $job instanceof SendQueuedNotifications => $job->notification,
            default => $job,
        };
    }

    /**
     * Set the given key / value pairs on the payload.
     */
    public function set(array $values): static
    {
        $this->decoded = array_merge($this->decoded, $values);

        $this->value = json_encode($this->decoded);

        return $this;
    }

    /**
     * Get the "command name" for the job.
     */
    public function commandName(): ?string
    {
        return Arr::get($this->decoded, 'data.commandName');
    }

    /**
     * Get the "display name" for the job.
     */
    public function displayName(): ?string
    {
        return Arr::get($this->decoded, 'displayName');
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->decoded);
    }

    /**
     * Get the value at the current offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->decoded[$offset];
    }

    /**
     * Set the value at the current offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->decoded[$offset] = $value;
    }

    /**
     * Unset the value at the current offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->decoded[$offset]);
    }
}
