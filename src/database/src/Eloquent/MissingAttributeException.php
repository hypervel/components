<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use OutOfBoundsException;

class MissingAttributeException extends OutOfBoundsException
{
    /**
     * Create a new missing attribute exception instance.
     */
    public function __construct(Model $model, string $key)
    {
        parent::__construct(sprintf(
            'The attribute [%s] either does not exist or was not retrieved for model [%s].',
            $key,
            get_class($model)
        ));
    }
}
