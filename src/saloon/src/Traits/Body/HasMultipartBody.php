<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Repositories\Body\MultipartBodyRepository;

trait HasMultipartBody
{
    /**
     * Resolve the default multipart values.
     *
     * @return list<MultipartValue>
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
        return new MultipartBodyRepository($this->defaultBody());
    }
}
