<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Exceptions;

use LogicException;

class ResourceClassNotFoundException extends LogicException
{
    /**
     * Create an exception for a model without a discoverable resource class.
     */
    public static function forModel(string $model): static
    {
        return new static(sprintf('Failed to find resource class for model [%s].', $model));
    }
}
