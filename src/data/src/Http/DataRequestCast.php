<?php

declare(strict_types=1);

namespace Hypervel\Data\Http;

use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Data\Contracts\BaseData;

class DataRequestCast implements CastsRequestInput
{
    /**
     * Create a request data cast.
     *
     * @param class-string<BaseData> $dataClass
     */
    public function __construct(protected readonly string $dataClass)
    {
    }

    /**
     * Transform an input value into data.
     */
    public function cast(string $key, mixed $value, array $input): ?BaseData
    {
        if ($value === null) {
            return null;
        }

        return ($this->dataClass)::from($value);
    }
}
