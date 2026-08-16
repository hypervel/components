<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Repositories\Body\FormBodyRepository;

trait HasFormBody
{
    /**
     * Apply the form body defaults.
     */
    public function bootHasFormBody(PendingRequest $pendingRequest): void
    {
        if (! $pendingRequest->hasHeader('Content-Type')) {
            $pendingRequest->contentType('application/x-www-form-urlencoded');
        }
    }

    /**
     * Resolve the default form body.
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
        return new FormBodyRepository($this->defaultBody());
    }
}
