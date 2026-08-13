<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Repositories\Body;

use Hypervel\Saloon\Http\StructuredDataNormalizer;
use Hypervel\Saloon\Traits\Body\CreatesStreamFromString;
use Stringable;

class FormBodyRepository extends ArrayBodyRepository implements Stringable
{
    use CreatesStreamFromString;

    /**
     * Convert into a string.
     */
    public function __toString(): string
    {
        /** @var array<array-key, mixed> $data */
        $data = StructuredDataNormalizer::forUrlEncoding($this->all());

        return http_build_query($data);
    }
}
