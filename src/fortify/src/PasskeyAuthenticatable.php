<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Passkeys\PasskeyAuthenticatable as BasePasskeyAuthenticatable;

/**
 * @phpstan-require-implements \Hypervel\Fortify\Contracts\PasskeyUser
 */
trait PasskeyAuthenticatable
{
    use BasePasskeyAuthenticatable;
}
