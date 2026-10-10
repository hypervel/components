<?php

declare(strict_types=1);

namespace Hypervel\Ai\Jobs;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Attributes\WithoutBroadcasting;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\Concerns\InvokesQueuedResponseCallbacks;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Broadcasting\Channel;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Support\Str;
use Throwable;

use function Hypervel\Ai\ulid;

class BroadcastAgent implements ShouldQueue
{
    use InvokesQueuedResponseCallbacks {
        failed as protected invokeFailureCallbacks;
    }
    use Queueable;

    public int $tries = 1;

    public string $invocationId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public Decisions|string $prompt,
        public Channel|array $channels,
        public array $attachments = [],
        public Provider|Lab|array|string|null $provider = null,
        public ?string $model = null,
    ) {
        $this->invocationId = (string) Str::uuid7();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $streamedResponse = null;

        $without = WithoutBroadcasting::eventsFor($this->agent);

        $this->agent->stream($this->prompt, $this->attachments, $this->provider, $this->model)
            ->each(function (StreamEvent $event) use ($without): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->withInvocationId($this->invocationId)->broadcastNow($this->channels);
            })
            ->then(function (StreamedAgentResponse $response) use (&$streamedResponse): void {
                $streamedResponse = $response;
            });

        $this->withCallbacks(fn (): ?StreamedAgentResponse => $streamedResponse);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        (new Error(
            id: ulid(),
            type: 'stream_failed',
            message: 'The stream failed.',
            recoverable: false,
            timestamp: time(),
        ))->withInvocationId($this->invocationId)
            ->broadcastNow($this->channels);

        $this->invokeFailureCallbacks($exception);
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
