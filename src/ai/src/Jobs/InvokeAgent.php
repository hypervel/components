<?php

declare(strict_types=1);

namespace Hypervel\Ai\Jobs;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\Concerns\InvokesQueuedResponseCallbacks;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;

class InvokeAgent implements ShouldQueue
{
    use InvokesQueuedResponseCallbacks;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public Decisions|string $prompt = '',
        public array $attachments = [],
        public Provider|Lab|array|string|null $provider = null,
        public ?string $model = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn (): AgentResponse => $this->agent->prompt(
            $this->prompt,
            $this->attachments,
            $this->provider,
            $this->model
        ));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }
}
