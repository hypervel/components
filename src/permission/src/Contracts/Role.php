<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;

/**
 * @mixin \Hypervel\Permission\Models\Role
 */
interface Role
{
    /**
     * A role may be given various permissions.
     */
    public function permissions(): BelongsToMany;
}
