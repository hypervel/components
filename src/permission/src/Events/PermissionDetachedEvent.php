<?php

declare(strict_types=1);

namespace Hypervel\Permission\Events;

use Hypervel\Broadcasting\InteractsWithSockets;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Collection;

class PermissionDetachedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Internally the HasPermissions trait passes $permissionsOrIds as an Eloquent record.
     * Theoretically one could register the event to other places and pass an array etc.
     * So a Listener should inspect the type of $permissionsOrIds received before using.
     *
     * @param array<array-key, int|string>|array<array-key, Permission>|Collection<array-key, mixed>|Permission $permissionsOrIds
     */
    public function __construct(public Model $model, public mixed $permissionsOrIds)
    {
    }
}
