<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Queue\Contracts\EntityNotFoundException;
use Hypervel\Queue\Contracts\EntityResolver as EntityResolverContract;

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
