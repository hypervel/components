<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify\Fixtures;

class Admin extends UserWithTwoFactor
{
    protected ?string $table = 'admins';
}
