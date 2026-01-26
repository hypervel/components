<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Contracts\Queue\EntityResolver as EntityResolverContract;
use Hypervel\Queue\Exceptions\EntityNotFoundException;

class QueueEntityResolver implements EntityResolverContract
{
    /**
     * Resolve the entity for the given ID.
     *
     * @throws EntityNotFoundException
     */
    public function resolve(string $type, mixed $id): mixed
    {
        $instance = (new $type())->find($id);

        if ($instance) {
            return $instance;
        }

        throw new EntityNotFoundException($type, $id);
    }
}
