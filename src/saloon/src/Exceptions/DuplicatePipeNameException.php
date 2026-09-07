<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

class DuplicatePipeNameException extends SaloonException
{
    /**
     * Create a duplicate pipe name exception.
     */
    public function __construct(string $name)
    {
        parent::__construct(sprintf('The "%s" pipe already exists on the pipeline.', $name));
    }
}
