<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;

interface UpdatesUserPasswords
{
    /**
     * Update the user's password.
     *
     * @param array<string, mixed> $input
     */
    public function update(Authenticatable $user, array $input): void;
}
