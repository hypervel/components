<?php

declare(strict_types=1);

namespace Hypervel\Ai\Jobs;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\Concerns\InvokesQueuedResponseCallbacks;
use Hypervel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;

class GenerateEmbeddings implements ShouldQueue
{
    use InvokesQueuedResponseCallbacks;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PendingEmbeddingsGeneration $pendingEmbeddings,
        public Provider|Lab|array|string|null $provider = null,
        public ?string $model = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn (): EmbeddingsResponse => $this->pendingEmbeddings->generate(
            $this->provider,
            $this->model,
        ));
    }
}
