<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Fixtures;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\PasskeyAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;

    protected array $guarded = [];
}
