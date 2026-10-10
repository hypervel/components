<?php

declare(strict_types=1);

namespace Hypervel\Ai\Jobs;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\Concerns\InvokesQueuedResponseCallbacks;
use Hypervel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;

class GenerateTranscription implements ShouldQueue
{
    use InvokesQueuedResponseCallbacks;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PendingTranscriptionGeneration $pendingTranscription,
        public Provider|Lab|array|string|null $provider = null,
        public ?string $model = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn (): TranscriptionResponse => $this->pendingTranscription->generate(
            $this->provider,
            $this->model,
        ));
    }
}
