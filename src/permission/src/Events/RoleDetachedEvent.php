<?php

declare(strict_types=1);

namespace Hypervel\Permission\Events;

use Hypervel\Broadcasting\InteractsWithSockets;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Collection;

class RoleDetachedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Internally the HasRoles trait passes an array of role ids (e.g. ints or UUIDs).
     * Theoretically one could register the event to other places passing other types.
     * So a Listener should inspect the type of $rolesOrIds received before using.
     *
     * @param array<array-key, int|string>|array<array-key, Role>|Collection<array-key, mixed>|Role $rolesOrIds
     */
    public function __construct(public Model $model, public mixed $rolesOrIds)
    {
    }
}
