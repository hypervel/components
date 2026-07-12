<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Contracts;

use Hypervel\Contracts\Auth\CanResetPassword;

interface ResetsUserPasswords
{
    /**
     * Reset the user's password.
     *
     * @param array<string, mixed> $input
     */
    public function reset(CanResetPassword $user, array $input): void;
}
