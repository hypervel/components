<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

class FixtureMissingException extends SaloonException
{
    /**
     * Create a missing fixture exception.
     */
    public function __construct(string $name)
    {
        parent::__construct(sprintf('The fixture "%s" could not be found in storage.', $name));
    }
}
