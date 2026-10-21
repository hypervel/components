<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Fortify\Contracts\PasskeyUser;
use Hypervel\Passkeys\PasskeyAuthenticatable as BasePasskeyAuthenticatable;

/**
 * @phpstan-require-implements PasskeyUser
 */
trait PasskeyAuthenticatable
{
    use BasePasskeyAuthenticatable;
}
