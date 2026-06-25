<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hypervel\Database\Eloquent\Model;

interface PermissionsTeamResolver
{
    /**
     * Get the current permissions team id.
     */
    public function getPermissionsTeamId(): int|string|null;

    /**
     * Set the current permissions team id.
     */
    public function setPermissionsTeamId(int|string|Model|null $id): void;
}
