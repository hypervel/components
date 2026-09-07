<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Repositories\Body\StreamBodyRepository;
use Psr\Http\Message\StreamInterface;

trait HasStreamBody
{
    /**
     * Resolve the default stream body.
     *
     * @return null|resource|StreamInterface
     */
    protected function defaultBody(): mixed
    {
        return null;
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): ?BodyRepository
    {
        return new StreamBodyRepository($this->defaultBody());
    }
}
