<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;

interface CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param array<string, mixed> $input
     */
    public function create(array $input): Authenticatable;
}
