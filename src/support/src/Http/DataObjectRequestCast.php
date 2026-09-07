<?php

declare(strict_types=1);

namespace Hypervel\Support\Http;

use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Support\DataObject;
use InvalidArgumentException;

class DataObjectRequestCast implements CastsRequestInput
{
    /**
     * Create a request data object cast.
     *
     * @param class-string<DataObject> $dataObjectClass
     */
    public function __construct(protected readonly string $dataObjectClass)
    {
    }

    /**
     * Transform validated request input into a data object.
     */
    public function cast(string $key, mixed $value, array $input): ?DataObject
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot cast request input [%s] to data object [%s]: expected array, received %s.',
                $key,
                $this->dataObjectClass,
                get_debug_type($value),
            ));
        }

        return ($this->dataObjectClass)::from($value);
    }
}
