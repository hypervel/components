<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hyperf\Database\Model\Relations\BelongsToMany;

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
