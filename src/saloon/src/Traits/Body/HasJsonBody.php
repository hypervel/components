<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;

trait HasJsonBody
{
    /**
     * Apply the JSON body defaults.
     */
    public function bootHasJsonBody(PendingRequest $pendingRequest): void
    {
        if (! $pendingRequest->hasHeader('Content-Type')) {
            $pendingRequest->contentType('application/json');
        }
    }

    /**
     * Resolve the default JSON body.
     *
     * @return array<array-key, mixed>
     */
    protected function defaultBody(): array
    {
        return [];
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): ?BodyRepository
    {
        return new JsonBodyRepository($this->defaultBody());
    }
}
