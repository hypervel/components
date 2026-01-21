<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;

/**
 * @mixin \Hypervel\Permission\Models\Permission
 */
interface Permission
{
    /**
     * A role may be given various permissions.
     */
    public function roles(): BelongsToMany;
}
