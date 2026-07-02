<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify\Fixtures;

use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\TwoFactorAuthenticatable;
use Workbench\App\Models\User;

class UserWithTwoFactor extends User implements TwoFactorAuthenticationUser
{
    use TwoFactorAuthenticatable;

    protected ?string $table = 'users';

    protected array $guarded = [];
}
