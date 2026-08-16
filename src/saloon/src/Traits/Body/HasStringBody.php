<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;

trait HasStringBody
{
    /**
     * Resolve the default string body.
     */
    protected function defaultBody(): ?string
    {
        return null;
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): ?BodyRepository
    {
        return new StringBodyRepository($this->defaultBody());
    }
}
