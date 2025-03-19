<?php

declare(strict_types=1);

namespace Hypervel\Queue\Exceptions;

use InvalidArgumentException;

class EntityNotFoundException extends InvalidArgumentException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $type, mixed $id)
    {
        $id = (string) $id;

        parent::__construct("Queueable entity [{$type}] not found for ID [{$id}].");
    }
}
